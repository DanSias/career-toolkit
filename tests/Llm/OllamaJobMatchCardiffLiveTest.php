<?php

use App\Exceptions\InvalidJobMatchResponseException;
use App\Models\CareerProfile;
use App\Support\JobMatch\CandidatePayloadBuilder;
use App\Support\JobMatch\JobMatchResponseValidator;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\JobMatch\Prompts\JobMatchPromptV1;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;

/**
 * Opt-in, real-provider check that JobMatch's real, unmodified contract
 * (JobMatchPromptV1, JobMatchResponseValidator, CandidatePayloadBuilder,
 * JobPayloadBuilder) works against a local Ollama server, using the
 * EXACT validated 30-finding Cardiff JobAnalysis already produced by
 * the reasoning_effort:none live Job Analysis experiment — not a
 * regenerated one. Lives outside phpunit.xml's default testsuites via
 * tests/Pest.php's `->in('Llm')` registration, so it never runs as
 * part of `php artisan test`. Run explicitly, by this exact file path
 * only:
 *
 *     vendor/bin/pest tests/Llm/OllamaJobMatchCardiffLiveTest.php
 *
 * tests/Llm/fixtures/cardiff-job-analysis-reasoning-effort-none.json is
 * a byte-for-byte data copy of that run's captured structuredContent
 * (verified via a decoded-array equality check when it was written —
 * not hand-transcribed). It is used verbatim, including its known
 * imperfections: no domain_knowledge finding; us_remote's unsupported
 * location-to-authorization inference; niche_tech_pref's misplaced AWS
 * evidence excerpt; source_locator null throughout. None of that is
 * corrected here — this harness's whole point is to feed Job Match
 * exactly what that upstream run actually produced, flaws included, so
 * any Job Match weakness can be attributed to the right stage.
 *
 * Reuse constraint, stated plainly: JobPayloadBuilder (used unchanged,
 * per this experiment's instructions) requires a real Eloquent
 * JobAnalysis with real, database-assigned JobAnalysisFinding ids — it
 * has no "build from a raw array" mode. Since no persisted Cardiff
 * JobAnalysis exists anywhere (established in the prior investigation
 * turn — neither the normal dev database nor database/live-eval.sqlite
 * has one), this harness persists JobPosting + JobAnalysis +
 * JobAnalysisFinding + JobAnalysisFindingEvidence rows built from the
 * fixture's literal field values — into the ephemeral,
 * RefreshDatabase-wrapped `:memory:` test connection ONLY (per
 * phpunit.xml's DB_DATABASE=:memory:), exactly mirroring what
 * GenerateJobAnalysis::generate()'s own persistence step does
 * internally, and the same precedent already used by
 * tests/Llm/JobAnalysisLiveCorpusTest.php's jobAnalysisViaOpenAI() and
 * this file's own Pearly sibling (OllamaJobMatchLiveTest.php). Never
 * writes to the real dev database or to database/live-eval.sqlite.
 * This is NOT a second provider call and NOT a regeneration.
 *
 * The real canonical candidate corpus (77 CareerFacts, 3 Education) is
 * read from database/live-eval.sqlite by temporarily switching
 * `database.default` to `sqlite_live_eval` for that one read, then
 * restoring it — same pattern as the Pearly sibling harness.
 *
 * Calls OllamaChatCompletionsClient directly rather than through
 * OllamaJobMatchClient, for the same already-established reason:
 * JobMatchProviderResponse doesn't expose finish_reason/usage, which
 * this harness's diagnostics need. Sends the exact same request
 * OllamaJobMatchClient would (schema name 'job_match', 12,000-token
 * budget matching OllamaJobMatchClient::MAX_OUTPUT_TOKENS), plus this
 * one experiment's reasoning_effort: 'none'.
 *
 * No persistence of any JobMatch — GenerateJobMatch is never called.
 *
 * A JobMatchResponseValidator failure fails this test, unchanged from
 * the Pearly sibling harness's behavior — schema/ID fidelity is
 * exactly the thing this class of experiment exists to check.
 */
// 16000, not the production 12000 (OllamaJobMatchClient::MAX_OUTPUT_TOKENS) —
// deliberately raised for this one controlled output-budget experiment after
// the 12000 attempt hit finish_reason:length. This is a harness-only
// deviation, not a production-config change: OllamaJobMatchClient's own
// constant is untouched.
const OLLAMA_JOB_MATCH_CARDIFF_MAX_OUTPUT_TOKENS = 16000;

beforeEach(function () {
    if (blank(config('services.ollama.base_url'))) {
        $this->markTestSkipped('No OLLAMA_BASE_URL configured — skipping live Ollama Job Match check.');
    }

    $liveEvalDatabasePath = config('database.connections.sqlite_live_eval.database');

    if (! is_string($liveEvalDatabasePath) || ! file_exists($liveEvalDatabasePath)) {
        $this->markTestSkipped("No live-eval database found at [{$liveEvalDatabasePath}] — skipping live Ollama Job Match check.");
    }
});

it('runs the real JobMatch prompt/schema through Ollama against the exact captured Cardiff reasoning_effort:none JobAnalysis and the real canonical profile', function () {
    $fixturePath = __DIR__.'/fixtures/cardiff-job-analysis-reasoning-effort-none.json';
    $cardiffJobAnalysis = json_decode(file_get_contents($fixturePath), true);

    expect($cardiffJobAnalysis['findings'])->toHaveCount(30, 'The Cardiff fixture no longer matches the captured 30-finding run — stop and investigate rather than proceeding.');

    $posting = jobAnalysisCorpusPosting('Cardiff');

    $analysis = $posting->jobAnalyses()->create([
        'schema_version' => '1.0',
        'prompt_version' => 'job-analysis-v2',
        'generated_by' => 'ollama:qwen3.8:27b',
        'generated_at' => now(),
        'raw_response' => $cardiffJobAnalysis,
        'role_summary' => $cardiffJobAnalysis['role_summary'],
        'overall_seniority' => $cardiffJobAnalysis['overall_seniority'],
        'seniority_rationale' => $cardiffJobAnalysis['seniority_rationale'],
    ]);

    foreach ($cardiffJobAnalysis['findings'] as $findingData) {
        $finding = $analysis->findings()->create([
            'category' => $findingData['category'],
            'statement' => $findingData['statement'],
            'label' => $findingData['label'],
            'basis' => $findingData['basis'],
            'requirement_strength' => $findingData['requirement_strength'],
            'emphasis' => $findingData['emphasis'],
            'maturity' => $findingData['maturity'],
            'years_experience_min' => $findingData['years_experience_min'],
            'years_experience_max' => $findingData['years_experience_max'],
            'recency_requirement' => $findingData['recency_requirement'],
            'time_horizon' => $findingData['time_horizon'],
            'notes' => $findingData['notes'],
        ]);

        foreach ($findingData['evidence'] as $evidenceData) {
            $finding->evidence()->create([
                'excerpt' => $evidenceData['excerpt'],
                'source_section' => $evidenceData['source_section'],
                'source_locator' => $evidenceData['source_locator'],
            ]);
        }
    }

    $originalConnection = config('database.default');
    config(['database.default' => 'sqlite_live_eval']);
    $profile = CareerProfile::findOrFail(1);
    $candidatePayload = (new CandidatePayloadBuilder)->build($profile);
    config(['database.default' => $originalConnection]);

    $jobPayload = (new JobPayloadBuilder)->build($analysis);

    $transport = new OllamaChatCompletionsClient;
    $prompt = new JobMatchPromptV1;
    $validator = new JobMatchResponseValidator;

    $validFindingIds = array_column($jobPayload['findings'], 'id');
    $validFactKeys = array_column($candidatePayload['career_facts'], 'key');
    $validEducationIds = array_column($candidatePayload['education'], 'id');

    $report = [
        'job_analysis_source' => 'exact captured reasoning_effort:none Cardiff run (fixture, not regenerated)',
        'provider' => 'ollama',
        'configured_model' => config('services.ollama.model'),
        'returned_model' => null,
        'reasoning_effort_requested' => 'none',
        'max_tokens_requested' => OLLAMA_JOB_MATCH_CARDIFF_MAX_OUTPUT_TOKENS,
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
            maxOutputTokens: OLLAMA_JOB_MATCH_CARDIFF_MAX_OUTPUT_TOKENS,
            timeoutSeconds: (int) config('services.ollama.timeout'),
            logPrefix: 'JobMatch generation (Ollama live check, Cardiff no-reasoning)',
            reasoningEffort: 'none',
        );
    } catch (OllamaChatCompletionsException $e) {
        $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
        $report['returned_model'] = $e->model;
        $report['finish_reason'] = $e->finishReason;
        $report['prompt_tokens'] = $e->usage['prompt_tokens'] ?? null;
        $report['completion_tokens'] = $e->usage['completion_tokens'] ?? null;
        $report['total_tokens'] = $e->usage['total_tokens'] ?? null;
        printJobMatchCardiffReport($report);
        throw $e;
    }

    $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
    $report['returned_model'] = $result->model;
    $report['finish_reason'] = $result->finishReason;
    $report['prompt_tokens'] = $result->usage['prompt_tokens'] ?? null;
    $report['completion_tokens'] = $result->usage['completion_tokens'] ?? null;
    $report['total_tokens'] = $result->usage['total_tokens'] ?? null;

    try {
        $validated = $validator->validate($result->structuredContent, $validFindingIds, $validFactKeys, $validEducationIds);
    } catch (InvalidJobMatchResponseException $e) {
        printJobMatchCardiffReport($report);
        fwrite(STDERR, "\n=== Ollama Job Match (Cardiff, no-reasoning) live check: VALIDATION FAILED ===\n".$e->getMessage().PHP_EOL);
        printJobMatchCardiffStructuredContent($result->structuredContent);
        throw $e;
    }

    $findings = $validated['findings'];

    $report['validated_findings'] = count($findings);
    $report['coverage_distribution'] = countByKeyCardiff($findings, 'coverage');
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

    printJobMatchCardiffReport($report);
    printJobMatchCardiffStructuredContent($validated);

    expect($validated['findings'])->not->toBeEmpty();
});

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, int>
 */
function countByKeyCardiff(array $rows, string $key): array
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
function printJobMatchCardiffReport(array $report): void
{
    fwrite(STDERR, "\n".'=== Ollama Job Match (Cardiff, no-reasoning) live check: report ==='."\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

/**
 * @param  array<string, mixed>  $structuredContent
 */
function printJobMatchCardiffStructuredContent(array $structuredContent): void
{
    fwrite(STDERR, "\n".'=== Ollama Job Match (Cardiff, no-reasoning) live check: structured content ==='."\n".json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
}
