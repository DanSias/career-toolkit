<?php

namespace App\Console\Commands\LiveEval;

use App\Enums\Visibility;
use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Exceptions\InvalidJobMatchResponseException;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Exceptions\JobAnalysisProviderException;
use App\Exceptions\JobMatchProviderException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\CurrentCareerProfile;
use App\Support\JobAnalysis\GenerateJobAnalysis;
use App\Support\JobMatch\GenerateJobMatch;
use App\Support\ResumeVariant\DiscoveryPreflight;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeEligibility;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Opt-in, paid, real-provider live evaluation of the ResumeVariant
 * pipeline (and, when not already persisted, its upstream JobAnalysis/
 * JobMatch) against a dedicated, persistent, file-backed SQLite
 * database — never database/database.sqlite, never PHPUnit/Pest's
 * :memory: test database. See docs/resume-variant-generation.md "Live
 * evaluation".
 *
 * The whole point of a persistent evaluation database is that an
 * accepted chain survives between invocations: this command always
 * locates-or-generates JobPosting -> JobAnalysis -> JobMatch (only
 * paying for whichever of these don't already exist for the requested
 * corpus posting), then always generates a fresh ResumeVariant — every
 * invocation is a new, independent evaluation of the ResumeVariant
 * layer specifically, consistent with the production app's own
 * immutable-snapshot model (no "current" pointer anywhere in this
 * pipeline).
 *
 * Deliberately NOT a Pest test: this needs stateful behavior across
 * separate process invocations (idempotent, non-destructive
 * migration, reuse-by-identity), which Pest's RefreshDatabase-per-run
 * model isn't built for, and keeping it a plain Artisan command keeps
 * it structurally impossible for `vendor/bin/pest` to reach — no
 * shared Pest binding, no shared TestCase, no accidental discovery.
 */
#[Signature('live-eval:resume-variant
    {postingPrefix=Pearly : Company-name prefix identifying the sources/jobs/job-analysis-design-set.md posting to evaluate}
    {--dry-run : Run every setup/eligibility check and report the plan without making any provider call or persisting a new artifact}')]
#[Description('Opt-in, paid, real-provider live evaluation of the ResumeVariant pipeline against the isolated live-eval SQLite database.')]
class RunResumeVariantLiveEvaluation extends Command
{
    private const CONNECTION = 'sqlite_live_eval';

    /**
     * The seven CareerFacts reclassified Restricted -> Private in
     * commit 578caab. Must never appear in ResumeEligibility's output.
     */
    private const SEVEN_PRIVATE_REMEDIATION_FACT_KEYS = [
        'rocketgate-transaction-remediation-phase1-transactions-corrected',
        'rocketgate-transaction-remediation-phase2-initial-scope-expansion',
        'rocketgate-transaction-remediation-phase2-transactions-corrected',
        'rocketgate-transaction-remediation-phase2-missing-values-backfilled',
        'rocketgate-transaction-remediation-phase2-conflicts-resolved',
        'rocketgate-transaction-remediation-final-audit-population',
        'rocketgate-transaction-remediation-final-audit-desired-state',
    ];

    /**
     * The conservative "32,000+" headline — deliberately left Restricted
     * (not Private) and must remain eligible.
     */
    private const TOTAL_CORRECTED_FACT_KEY = 'rocketgate-transaction-remediation-total-corrected';

    public function handle(): int
    {
        $this->switchToLiveEvalConnection();
        $this->ensureLiveEvalDatabaseMigrated();

        $this->info('Importing canonical career data into the live-eval database (idempotent, safe to re-run)...');
        Artisan::call('career:import', [], $this->output);

        $profile = CurrentCareerProfile::resolve();

        $postingPrefix = (string) $this->argument('postingPrefix');
        $corpusPosting = $this->parseCorpusPosting($postingPrefix);

        $existingPosting = JobPosting::query()
            ->where('career_profile_id', $profile->id)
            ->where('company', $corpusPosting['company'])
            ->where('title', $corpusPosting['title'])
            ->first();

        $existingAnalysis = $existingPosting?->jobAnalyses()->latest('id')->first();

        $existingMatch = $existingAnalysis === null ? null : JobMatch::query()
            ->where('job_analysis_id', $existingAnalysis->id)
            ->where('career_profile_id', $profile->id)
            ->latest('id')
            ->first();

        $plannedCalls = [];
        if ($existingAnalysis === null) {
            $plannedCalls[] = 'JobAnalysis';
        }
        if ($existingMatch === null) {
            $plannedCalls[] = 'JobMatch';
        }
        $plannedCalls[] = 'Resume Selection';
        $plannedCalls[] = 'Resume Wording';

        $eligibilityOk = $this->printPreflightReport($profile, $corpusPosting, $existingPosting, $existingAnalysis, $existingMatch, $plannedCalls);

        if (! $eligibilityOk) {
            $this->error('One or more safety/eligibility checks failed — aborting before any provider call.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run complete — no provider call made, no new artifact persisted.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('=== Running the chain ===');

        // ---- JobPosting (free — no provider call) ----
        $posting = $existingPosting ?? JobPosting::create([
            'career_profile_id' => $profile->id,
            'company' => $corpusPosting['company'],
            'title' => $corpusPosting['title'],
            'description' => $corpusPosting['description'],
            'location' => null,
            'source_url' => null,
        ]);
        $this->info(($existingPosting === null ? 'Created' : 'Reused')." JobPosting #{$posting->id} — {$posting->company} / {$posting->title}");

        // ---- JobAnalysis ----
        $analysis = $existingAnalysis;
        if ($analysis === null) {
            $this->info('No existing JobAnalysis for this posting — generating (paid call)...');

            try {
                $analysis = app(GenerateJobAnalysis::class)->generate($posting);
            } catch (JobAnalysisProviderException|InvalidJobAnalysisResponseException $e) {
                $this->error('JobAnalysis generation failed: '.$e->getMessage());

                return self::FAILURE;
            }
            $this->info("Generated JobAnalysis #{$analysis->id} (schema_version {$analysis->schema_version}).");
        } else {
            $this->info("Reused existing JobAnalysis #{$analysis->id} (schema_version {$analysis->schema_version}) — no JobAnalysis call made.");
        }

        // ---- JobMatch ----
        $match = $existingMatch;
        if ($match === null) {
            $this->info("No existing JobMatch for JobAnalysis #{$analysis->id} / this CareerProfile — generating (paid call), consuming JobAnalysis #{$analysis->id}...");

            try {
                $match = app(GenerateJobMatch::class)->generate($analysis, $profile);
            } catch (JobMatchProviderException|InvalidJobMatchResponseException $e) {
                $this->error('JobMatch generation failed: '.$e->getMessage());

                return self::FAILURE;
            }
            $this->info("Generated JobMatch #{$match->id} (schema_version {$match->schema_version}), consuming JobAnalysis #{$analysis->id}.");
        } else {
            $this->info("Reused existing JobMatch #{$match->id} — no JobMatch call made.");
        }

        // ---- Discovery preflight (free — deterministic, no provider call) ----
        $candidates = (new DiscoveryPreflight)->run($match);
        $this->info('Discovery preflight (deterministic, no provider call) surfaced '.count($candidates).' candidate(s), consuming JobMatch #'.$match->id.':');
        if ($candidates === []) {
            $this->line('  (none)');
        }
        foreach ($candidates as $candidate) {
            $this->line("  - [tier {$candidate['tier']}] {$candidate['prompt']}");
        }

        // ---- ResumeVariant: Selection + Wording (2 paid calls) ----
        $this->info("Generating ResumeVariant from JobMatch #{$match->id} (paid calls: Resume Selection, Resume Wording)...");

        try {
            $variant = app(GenerateResumeVariant::class)->generateFull($match);
        } catch (InvalidResumeVariantResponseException $e) {
            $this->error('ResumeVariant generation failed: '.$e->getMessage());
            $this->captureFailedResponse($e);

            return self::FAILURE;
        } catch (ResumeGenerationProviderException $e) {
            $this->error('ResumeVariant generation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Generated ResumeVariant #{$variant->id}, consuming JobMatch #{$match->id} (schema_version {$variant->schema_version}, selection_prompt_version {$variant->selection_prompt_version}, wording_prompt_version {$variant->wording_prompt_version}).");

        $this->newLine();
        $this->info('=== Persisted chain ===');
        $this->line("  JobPosting     #{$posting->id}");
        $this->line("  JobAnalysis    #{$analysis->id}");
        $this->line("  JobMatch       #{$match->id}");
        $this->line("  ResumeVariant  #{$variant->id}");
        $this->line('  Live-eval DB:  '.config('database.connections.'.self::CONNECTION.'.database'));

        return self::SUCCESS;
    }

    /**
     * Persists a rejected Resume Selection/Wording response's raw,
     * already-decoded structured content to a local, gitignored
     * evaluation artifact — closing the diagnostic gap discovered
     * across the first two failed Pearly Selection attempts, where
     * the raw response was lost the moment validation rejected it.
     *
     * Live-eval-only: this never touches production persistence (no
     * ResumeVariant row, no new table), never weakens or bypasses
     * `ResumeSelectionResponseValidator`/`ResumeWordingResponseValidator`
     * (both still reject exactly as before — this only reads the
     * `$context` they already attach to the exception they already
     * throw), and never captures chain-of-thought: neither Selection's
     * nor Wording's structured-output schema has any free-text
     * reasoning field at all — `$context` is pure data (role_id,
     * display_title, bullet_groups, project_id, career_fact_keys,
     * job_analysis_finding_ids, target_term_usages for Selection;
     * summary/bullet text only for Wording).
     *
     * Written via the `local` disk, which resolves to
     * storage/app/private — already fully gitignored by Laravel's
     * default storage/app/private/.gitignore (`*`), so no new
     * .gitignore entry is needed and this can never be accidentally
     * committed.
     */
    private function captureFailedResponse(InvalidResumeVariantResponseException $e): void
    {
        if ($e->context === null) {
            return;
        }

        $stage = str_contains($e->getMessage(), 'Resume Selection') ? 'selection' : 'wording';
        $filename = 'live-eval/'.now()->format('Y-m-d_His').'-'.$stage.'-failure.json';

        $contents = json_encode([
            'stage' => $stage,
            'captured_at' => now()->toIso8601String(),
            'validation_message' => $e->getMessage(),
            'decoded_response' => $e->context,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($contents === false) {
            $this->warn('Could not JSON-encode the rejected response for capture.');

            return;
        }

        Storage::disk('local')->put($filename, $contents);

        $this->warn('Captured the rejected decoded response to: '.Storage::disk('local')->path($filename));
    }

    /**
     * Redirects the default connection to the dedicated, persistent
     * live-eval database for the remainder of THIS process only — no
     * .env file, phpunit.xml, or global config is touched. Every
     * Eloquent model in this app resolves its connection via
     * config('database.default') (none hardcode a connection), so this
     * single override is sufficient to redirect the entire pipeline
     * (career:import, GenerateJobAnalysis, GenerateJobMatch,
     * GenerateResumeVariant) without modifying any of that production
     * code.
     */
    private function switchToLiveEvalConnection(): void
    {
        config(['database.default' => self::CONNECTION]);

        $path = config('database.connections.'.self::CONNECTION.'.database');

        if (! is_string($path)) {
            throw new RuntimeException('Live-eval database path did not resolve to a string.');
        }

        if (! is_dir(dirname($path))) {
            throw new RuntimeException("Live-eval database directory does not exist: [{$path}].");
        }

        if (! file_exists($path)) {
            touch($path);
            $this->info("Created live-eval database file at [{$path}].");
        }
    }

    /**
     * Applies pending migrations only — never migrate:fresh. Safe to
     * run on every invocation: a first run creates the full schema on
     * an empty file, a later run against an already-migrated file is a
     * no-op, and previously accepted rows are never touched either way.
     */
    private function ensureLiveEvalDatabaseMigrated(): void
    {
        $this->info('Applying any pending migrations to the live-eval database (never migrate:fresh)...');
        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true], $this->output);
    }

    /**
     * Mirrors tests/Pest.php's jobAnalysisCorpusPostings() parsing
     * exactly (same section-splitting rule, same verbatim body capture)
     * so this command locates/reconstructs the identical design-corpus
     * posting the existing JobAnalysis/JobMatch live-eval suite already
     * uses — deliberately duplicated rather than imported, since
     * production console code must not depend on test-namespace helpers.
     *
     * @return array{company: string, title: string, description: string}
     */
    private function parseCorpusPosting(string $companyPrefix): array
    {
        $path = base_path('sources/jobs/job-analysis-design-set.md');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read corpus file at [{$path}].");
        }

        $sections = preg_split('/^## /m', $contents);

        if ($sections === false) {
            throw new RuntimeException("Could not parse corpus sections in [{$path}].");
        }

        array_shift($sections);

        $postings = array_map(function (string $section): array {
            [$headingLine, $rest] = explode("\n", $section, 2);
            [$company, $title] = array_map('trim', explode('—', $headingLine, 2));

            $body = preg_replace('/\n---\s*$/', '', rtrim($rest)) ?? rtrim($rest);
            $body = preg_replace('/^###\s*Original Job Description\s*$/mi', '', $body) ?? $body;

            return ['company' => $company, 'title' => $title, 'description' => trim($body)];
        }, $sections);

        $match = collect($postings)->firstWhere(fn (array $p) => str_starts_with($p['company'], $companyPrefix));

        if ($match === null) {
            throw new RuntimeException("No corpus posting found for company starting with [{$companyPrefix}] in [{$path}].");
        }

        return $match;
    }

    /**
     * Prints every check required before the first paid call and
     * returns whether all of them passed. Makes zero provider calls.
     *
     * @param  array{company: string, title: string, description: string}  $corpusPosting
     * @param  array<int, string>  $plannedCalls
     */
    private function printPreflightReport(
        CareerProfile $profile,
        array $corpusPosting,
        ?JobPosting $existingPosting,
        ?JobAnalysis $existingAnalysis,
        ?JobMatch $existingMatch,
        array $plannedCalls,
    ): bool {
        $this->info('=== Pre-flight report ===');

        $dbPath = config('database.connections.'.self::CONNECTION.'.database');
        $this->line("Live-eval DB path: {$dbPath}");
        $this->line('Live-eval DB is the active connection: '.(config('database.default') === self::CONNECTION ? 'yes' : 'NO — MISMATCH'));
        $ok = config('database.default') === self::CONNECTION;

        $this->newLine();
        $this->line("Corpus posting: {$corpusPosting['company']} — {$corpusPosting['title']}");
        $this->line('Existing JobPosting: '.($existingPosting === null ? 'none — will be created' : "#{$existingPosting->id} (reused)"));
        $this->line('Existing JobAnalysis: '.($existingAnalysis === null ? 'none — will be generated (paid call)' : "#{$existingAnalysis->id} (reused, no call)"));
        $this->line('Existing JobMatch: '.($existingMatch === null ? 'none — will be generated (paid call)' : "#{$existingMatch->id} (reused, no call)"));

        $this->newLine();
        $this->line('Live-eval DB current row counts:');
        $this->line('  JobPostings:    '.JobPosting::count());
        $this->line('  JobAnalyses:    '.JobAnalysis::count());
        $this->line('  JobMatches:     '.JobMatch::count());
        $this->line('  ResumeVariants: '.ResumeVariant::count());

        $this->newLine();
        $factCounts = CareerFact::query()
            ->where('career_profile_id', $profile->id)
            ->selectRaw('visibility, count(*) as c')
            ->groupBy('visibility')
            ->pluck('c', 'visibility');

        $public = (int) ($factCounts[Visibility::Public->value] ?? 0);
        $restricted = (int) ($factCounts[Visibility::Restricted->value] ?? 0);
        $private = (int) ($factCounts[Visibility::Private->value] ?? 0);

        $this->line("CareerFact visibility distribution: {$public} public / {$restricted} restricted / {$private} private");
        $visibilityOk = $public === 32 && $restricted === 12 && $private === 7;
        $this->line('Matches expected 32/12/7: '.($visibilityOk ? 'yes' : 'NO — MISMATCH'));
        $ok = $ok && $visibilityOk;

        $eligibleKeys = ResumeEligibility::eligibleCareerFactsQuery($profile)->pluck('key')->all();

        $leakedPrivateKeys = array_values(array_intersect(self::SEVEN_PRIVATE_REMEDIATION_FACT_KEYS, $eligibleKeys));
        $sevenPrivateAbsent = $leakedPrivateKeys === [];
        $this->line('Seven Private remediation facts excluded from ResumeEligibility: '.($sevenPrivateAbsent ? 'yes' : 'NO — LEAKED: '.implode(', ', $leakedPrivateKeys)));
        $ok = $ok && $sevenPrivateAbsent;

        $allSevenArePrivate = CareerFact::query()
            ->whereIn('key', self::SEVEN_PRIVATE_REMEDIATION_FACT_KEYS)
            ->where('visibility', '!=', Visibility::Private->value)
            ->doesntExist();
        $this->line('All seven remediation facts are actually visibility=private in canonical data: '.($allSevenArePrivate ? 'yes' : 'NO — MISMATCH'));
        $ok = $ok && $allSevenArePrivate;

        $totalCorrectedEligible = in_array(self::TOTAL_CORRECTED_FACT_KEY, $eligibleKeys, true);
        $this->line('rocketgate-transaction-remediation-total-corrected (32,000+) remains eligible: '.($totalCorrectedEligible ? 'yes' : 'NO — MISMATCH'));
        $ok = $ok && $totalCorrectedEligible;

        $this->newLine();
        $model = (string) config('services.openai.model');
        $this->line("Configured model: {$model}");
        $this->line('Expected successful provider calls this run: '.count($plannedCalls).' ('.implode(', ', $plannedCalls).')');

        $this->newLine();
        $this->line('All checks passed: '.($ok ? 'YES' : 'NO'));

        return $ok;
    }
}
