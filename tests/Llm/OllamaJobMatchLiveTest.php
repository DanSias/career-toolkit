<?php

use App\Exceptions\InvalidJobMatchResponseException;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Support\JobMatch\CandidatePayloadBuilder;
use App\Support\JobMatch\JobMatchResponseValidator;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\JobMatch\Prompts\JobMatchPromptV3;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;

/**
 * Opt-in, real-provider check that the existing JobMatch contract
 * (JobMatchPromptV3's prompt/schema, JobMatchResponseValidator) works
 * against a local Ollama server, on the real Pearly JobAnalysis and the
 * real canonical CareerProfile already persisted in the gitignored
 * database/live-eval.sqlite (never the normal dev/test database) — lives
 * outside phpunit.xml's default testsuites via tests/Pest.php's
 * `->in('Llm')` registration, so it never runs as part of
 * `php artisan test`. Run explicitly, by this exact file path only:
 *
 *     vendor/bin/pest tests/Llm/OllamaJobMatchLiveTest.php
 *
 * Unlike the other tests/Llm fixtures, this one does NOT build a fresh
 * posting/profile from sources/jobs/job-analysis-design-set.md — it
 * reads the specific, already-generated JobAnalysis (id=1, Pearly, 25
 * findings) and CareerProfile (id=1, 77 CareerFacts, 3 Education) that
 * already exist in database/live-eval.sqlite from a prior real live-eval
 * run, per the corpus investigation that chose this exact posting for
 * the first Ollama Job Match experiment. The `database.default`
 * connection is switched to `sqlite_live_eval` only for the duration of
 * loading that data (read-only: CareerProfile::findOrFail(),
 * JobAnalysis::with('jobPosting')::findOrFail(), and whatever
 * CandidatePayloadBuilder/JobPayloadBuilder query internally), then
 * restored, so this file's own RefreshDatabase-wrapped `:memory:` test
 * connection is untouched.
 *
 * Calls OllamaChatCompletionsClient directly rather than through
 * OllamaJobMatchClient, for the same reason
 * tests/Llm/OllamaJobAnalysisLiveTest.php calls the transport directly
 * rather than through OllamaJobAnalysisClient: OllamaJobMatchClient's
 * GeneratesJobMatch contract only returns JobMatchProviderResponse
 * (provider/model/structuredContent) — the same shape
 * OpenAIJobMatchClient returns — and is intentionally left unmodified.
 * finish_reason/usage, needed for this harness's diagnostics, live on
 * OllamaChatCompletionsResult instead. Calling the transport directly
 * still sends the exact same request OllamaJobMatchClient would: same
 * prompt, same schema, same schema name ('job_match'), same
 * output-token budget (OLLAMA_JOB_MATCH_MAX_OUTPUT_TOKENS below, which
 * must track OllamaJobMatchClient::MAX_OUTPUT_TOKENS). This is a
 * deliberate choice, not a fidelity shortcut — see the git history for
 * the equivalent reasoning on the JobAnalysis harness.
 *
 * Deliberately does NOT call GenerateJobMatch (which would persist a
 * JobMatch + JobMatchFinding + CareerFactMatch/EducationMatch tree) —
 * this harness calls the transport -> JobMatchResponseValidator
 * directly instead, using CandidatePayloadBuilder/JobPayloadBuilder/
 * JobMatchPromptV3 exactly as GenerateJobMatch would, so this check
 * never writes a JobMatch record anywhere, in any database.
 *
 * Unlike the JobAnalysis live harnesses, a JobMatchResponseValidator
 * failure DOES fail this test (not merely reported) — deliberately:
 * the open question this experiment exists to answer is specifically
 * whether qwen3.8:27b can satisfy JobMatch's much stricter schema
 * (exhaustive per-run dynamic-enum ID tracking across three identifier
 * spaces — see the corpus/architecture investigation), so a validation
 * failure here is the primary signal this harness is built to catch,
 * not a secondary quality note.
 *
 * The full decoded structuredContent, plus a surfaced (not asserted)
 * read of the same Pearly-specific guardrail checks
 * tests/Llm/JobMatchLiveCorpusTest.php already uses against the real
 * OpenAI provider, are printed to STDERR — not logged through the
 * application's normal Log facade, and not persisted anywhere.
 *
 * Defaults to omitting reasoning_effort (unchanged from every prior run
 * of this harness) and the production-matching 12,000-token budget; set
 * OLLAMA_LIVE_REASONING_EFFORT (e.g. "none") and/or
 * OLLAMA_LIVE_JOB_MATCH_MAX_TOKENS to opt one run into a different
 * configuration for a controlled comparison — same one-off-diagnostic
 * env-var pattern already used by OllamaJobAnalysisLiveTest.php.
 */
const OLLAMA_JOB_MATCH_MAX_OUTPUT_TOKENS = 12000; // must track OllamaJobMatchClient::MAX_OUTPUT_TOKENS

const OLLAMA_JOB_MATCH_LIVE_EVAL_JOB_ANALYSIS_ID = 1; // Pearly, 25 findings

const OLLAMA_JOB_MATCH_LIVE_EVAL_CAREER_PROFILE_ID = 1; // 77 CareerFacts, 3 Education

beforeEach(function () {
    if (blank(config('services.ollama.base_url'))) {
        $this->markTestSkipped('No OLLAMA_BASE_URL configured — skipping live Ollama Job Match check.');
    }

    $liveEvalDatabasePath = config('database.connections.sqlite_live_eval.database');

    if (! is_string($liveEvalDatabasePath) || ! file_exists($liveEvalDatabasePath)) {
        $this->markTestSkipped("No live-eval database found at [{$liveEvalDatabasePath}] — skipping live Ollama Job Match check.");
    }
});

it('runs the real JobMatch prompt/schema through Ollama against the real Pearly/canonical-profile data and reports every pipeline stage', function () {
    $originalConnection = config('database.default');
    config(['database.default' => 'sqlite_live_eval']);

    $profile = CareerProfile::findOrFail(OLLAMA_JOB_MATCH_LIVE_EVAL_CAREER_PROFILE_ID);
    $analysis = JobAnalysis::with('jobPosting')->findOrFail(OLLAMA_JOB_MATCH_LIVE_EVAL_JOB_ANALYSIS_ID);

    $candidatePayload = (new CandidatePayloadBuilder)->build($profile);
    $jobPayload = (new JobPayloadBuilder)->build($analysis);
    $posting = $analysis->jobPosting;

    config(['database.default' => $originalConnection]);

    $transport = new OllamaChatCompletionsClient;
    $prompt = new JobMatchPromptV3;
    $validator = new JobMatchResponseValidator;

    $validFindingIds = array_column($jobPayload['findings'], 'id');
    $validFactKeys = array_column($candidatePayload['career_facts'], 'key');
    $validEducationIds = array_column($candidatePayload['education'], 'id');

    $reasoningEffort = getenv('OLLAMA_LIVE_REASONING_EFFORT') ?: null;
    $maxOutputTokens = getenv('OLLAMA_LIVE_JOB_MATCH_MAX_TOKENS')
        ? (int) getenv('OLLAMA_LIVE_JOB_MATCH_MAX_TOKENS')
        : OLLAMA_JOB_MATCH_MAX_OUTPUT_TOKENS;

    $report = [
        'posting_company' => $posting->company,
        'posting_title' => $posting->title,
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
        'provider' => 'ollama',
        'configured_model' => config('services.ollama.model'),
        'returned_model' => null,
        'reasoning_effort_requested' => $reasoningEffort,
        'max_tokens_requested' => $maxOutputTokens,
        'elapsed_seconds' => null,
        'finish_reason' => null,
        'prompt_tokens' => null,
        'completion_tokens' => null,
        'total_tokens' => null,
        'supplied_findings' => count($validFindingIds),
        'supplied_career_facts' => count($validFactKeys),
        'supplied_education_records' => count($validEducationIds),
        'validated_findings' => null,
        'coverage_distribution' => null,
        'relationship_distribution' => null,
        'total_career_fact_matches' => null,
        'total_education_matches' => null,
    ];

    $start = microtime(true);

    try {
        $result = $transport->call(
            baseUrl: (string) config('services.ollama.base_url'),
            model: (string) config('services.ollama.model'),
            systemPrompt: $prompt->systemPrompt(),
            userPrompt: $prompt->userPrompt($candidatePayload, $jobPayload),
            schema: $prompt->jsonSchema($validFindingIds, $validFactKeys, $validEducationIds),
            schemaName: 'job_match',
            maxOutputTokens: $maxOutputTokens,
            timeoutSeconds: (int) config('services.ollama.timeout'),
            logPrefix: 'JobMatch generation (Ollama live check)',
            reasoningEffort: $reasoningEffort,
        );
    } catch (OllamaChatCompletionsException $e) {
        $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
        $report['returned_model'] = $e->model;
        $report['finish_reason'] = $e->finishReason;
        $report['prompt_tokens'] = $e->usage['prompt_tokens'] ?? null;
        $report['completion_tokens'] = $e->usage['completion_tokens'] ?? null;
        $report['total_tokens'] = $e->usage['total_tokens'] ?? null;
        printJobMatchReport($report);
        throw $e;
    }

    $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
    $report['returned_model'] = $result->model;
    $report['finish_reason'] = $result->finishReason;
    $report['prompt_tokens'] = $result->usage['prompt_tokens'] ?? null;
    $report['completion_tokens'] = $result->usage['completion_tokens'] ?? null;
    $report['total_tokens'] = $result->usage['total_tokens'] ?? null;

    // A JobMatchResponseValidator failure fails this test — see this
    // file's docblock for why that's deliberate here, unlike the
    // JobAnalysis live harnesses.
    try {
        $validated = $validator->validate($result->structuredContent, $validFindingIds, $validFactKeys, $validEducationIds);
    } catch (InvalidJobMatchResponseException $e) {
        printJobMatchReport($report);
        fwrite(STDERR, "\n=== Ollama Job Match live check: VALIDATION FAILED ===\n".$e->getMessage().PHP_EOL);
        printJobMatchStructuredContent($result->structuredContent);
        throw $e;
    }

    $findings = $validated['findings'];

    $report['validated_findings'] = count($findings);
    $report['coverage_distribution'] = countByKey($findings, 'coverage');
    $report['total_career_fact_matches'] = array_sum(array_map(fn (array $f) => count($f['matches']), $findings));
    $report['total_education_matches'] = array_sum(array_map(fn (array $f) => count($f['education_matches']), $findings));

    $allRelationships = [];
    foreach ($findings as $finding) {
        foreach ($finding['matches'] as $match) {
            $allRelationships[] = $match['relationship'];
        }
        foreach ($finding['education_matches'] as $match) {
            $allRelationships[] = $match['relationship'];
        }
    }
    $report['relationship_distribution'] = array_count_values($allRelationships);

    printJobMatchReport($report);
    printJobMatchStructuredContent($validated);
    printJobMatchGuardrailFlags($findings, $jobPayload);

    // Only the transport/decode/validation steps are asserted on — the
    // guardrail flags above are reported, not asserted, per this file's
    // docblock; semantic quality is a human-review question.
    expect($validated['findings'])->not->toBeEmpty();
});

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, int>
 */
function countByKey(array $rows, string $key): array
{
    $counts = [];
    foreach ($rows as $row) {
        $value = (string) $row[$key];
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    return $counts;
}

/**
 * @param  array<string, mixed>  $report
 */
function printJobMatchReport(array $report): void
{
    fwrite(STDERR, "\n".'=== Ollama Job Match live check: report ==='."\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

/**
 * @param  array<string, mixed>  $structuredContent
 */
function printJobMatchStructuredContent(array $structuredContent): void
{
    fwrite(STDERR, "\n".'=== Ollama Job Match live check: structured content ==='."\n".json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
}

/**
 * Surfaces (never asserts) the same Pearly-specific guardrail checks
 * tests/Llm/JobMatchLiveCorpusTest.php already uses against the real
 * OpenAI provider — same CareerFact keys, same predicates — so a human
 * can review this run against the identical trip-wires, without turning
 * any of this into a new generic production validator.
 *
 * @param  array<int, array<string, mixed>>  $findings
 * @param  array<string, mixed>  $jobPayload
 */
function printJobMatchGuardrailFlags(array $findings, array $jobPayload): void
{
    $flags = [];

    foreach ($findings as $finding) {
        foreach ($finding['matches'] as $match) {
            $key = $match['career_fact_key'];
            $rationale = $match['rationale'] ?? '';

            // 1. Transaction Toolkit vs Transaction Remediation numeric conflation (hard guardrail in the OpenAI corpus).
            if (in_array($key, ['rocketgate-transaction-toolkit-what-it-is', 'rocketgate-transaction-toolkit-local-first-security'], true)
                && (str_contains($rationale, '43,348') || str_contains($rationale, '32,000'))) {
                $flags[] = "VIOLATION: Toolkit fact [{$key}] rationale contains Transaction Remediation's numbers: \"{$rationale}\"";
            }

            // Related soft flag from the OpenAI corpus: the audited-count
            // fact (43,348) incorrectly described as "corrected". Note:
            // the separate "corrected approximately 32,000+" CareerFact
            // (rocketgate-transaction-remediation-total-corrected) was
            // never cited by the OpenAI corpus either, per the prior
            // investigation — this run may be the first live citation of
            // it in either provider, not a re-check of prior validated
            // behavior.
            if ($key === 'rocketgate-transaction-remediation-final-audit-population' && str_contains($rationale, 'corrected')) {
                $flags[] = "FLAG: 43,348-audited-vs-corrected conflation — [{$key}]: \"{$rationale}\"";
            }

            // 2. Nexus $25M+ visibility/tracking vs ownership/management language.
            if ($key === 'pearson-nexus-marketing-spend-visibility') {
                $flags[] = "FLAG: Nexus \$25M+ — verify tracked/visible framing, not owned/managed — [{$key}]: \"{$rationale}\"";
            }

            // 3. Merchant integration scope inflation.
            if (str_contains($key, 'merchant-integration') || str_contains($key, 'merchant')) {
                $flags[] = "FLAG: merchant-integration scope — [{$key}]: \"{$rationale}\"";
            }
        }
    }

    // 4. Current-location finding should be not_assessable with zero evidence.
    $locationFinding = collect($findings)->first(function (array $finding) use ($jobPayload) {
        $jaFinding = collect($jobPayload['findings'])->firstWhere('id', $finding['job_analysis_finding_id']);

        return $jaFinding !== null && (
            str_contains($jaFinding['statement'], 'New York')
            || str_contains($jaFinding['statement'], 'Santa Barbara')
            || str_contains($jaFinding['statement'], 'Atlanta')
        );
    });

    if ($locationFinding === null) {
        $flags[] = 'NOTE: no location-related finding found (expected one mentioning New York/Santa Barbara/Atlanta) — this JobAnalysis snapshot may differ from what the corpus investigation inspected.';
    } else {
        $ok = $locationFinding['coverage'] === 'not_assessable'
            && $locationFinding['matches'] === []
            && $locationFinding['education_matches'] === [];
        $status = $ok ? 'OK' : 'FLAG';
        $flags[] = "{$status}: current-location finding (job_analysis_finding_id={$locationFinding['job_analysis_finding_id']}) coverage={$locationFinding['coverage']}, matches=".count($locationFinding['matches']).', education_matches='.count($locationFinding['education_matches']);
    }

    // 5. Education relationship behavior — surfaced for manual review, not asserted.
    $degreeFinding = collect($findings)->first(function (array $finding) use ($jobPayload) {
        $jaFinding = collect($jobPayload['findings'])->firstWhere('id', $finding['job_analysis_finding_id']);

        return $jaFinding !== null && (
            str_contains(strtolower($jaFinding['statement']), 'degree')
            || str_contains($jaFinding['statement'], 'Computer Science')
        );
    });

    if ($degreeFinding === null) {
        $flags[] = 'NOTE: no degree/education-requirement finding found — this JobAnalysis snapshot may differ from what the corpus investigation inspected.';
    } else {
        $flags[] = "NOTE: degree finding (job_analysis_finding_id={$degreeFinding['job_analysis_finding_id']}) coverage={$degreeFinding['coverage']}, education_matches=".count($degreeFinding['education_matches']).' — see relationship/rationale in the full structured content above.';
    }

    fwrite(STDERR, "\n".'=== Ollama Job Match live check: guardrail flags (surfaced, not asserted) ==='."\n".implode("\n", $flags).PHP_EOL);
}
