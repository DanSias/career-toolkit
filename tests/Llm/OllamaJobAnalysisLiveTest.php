<?php

use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Support\JobAnalysis\EvidenceExcerptVerifier;
use App\Support\JobAnalysis\JobAnalysisResponseValidator;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV2;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;

/**
 * Opt-in, real-provider check that the existing JobAnalysis contract
 * (JobAnalysisPromptV2's prompt/schema, JobAnalysisResponseValidator,
 * EvidenceExcerptVerifier) works unchanged against a local Ollama
 * server — lives outside phpunit.xml's default testsuites via
 * tests/Pest.php's `->in('Llm')` registration (same mechanism as
 * JobAnalysisLiveCorpusTest.php), so it never runs as part of
 * `php artisan test`. Run explicitly:
 *
 *     vendor/bin/pest tests/Llm/OllamaJobAnalysisLiveTest.php
 *
 * Defaults to the Pearly corpus posting; set
 * OLLAMA_LIVE_JOB_ANALYSIS_POSTING to any other
 * jobAnalysisCorpusPosting() company prefix (e.g. Rootstock, Cardiff)
 * to run this same diagnostic against a different fixture for a
 * one-off comparison. Intentionally just one env var, not a
 * multi-posting runner — see the local corpus-comparison investigation
 * for why a generalized evaluation framework isn't warranted yet.
 *
 * Also defaults to omitting reasoning_effort entirely (the model's own
 * default thinking behavior applies, unchanged from every prior run of
 * this harness); set OLLAMA_LIVE_REASONING_EFFORT (e.g. "none") to
 * opt this one run into OllamaChatCompletionsClient's optional
 * reasoning_effort passthrough — added specifically to test the
 * thinking-token-overhead hypothesis raised by the Cardiff
 * finish_reason:length failure at the default 8192-token budget. Not
 * a config() entry either, same one-off-diagnostic reasoning as the
 * posting override above.
 *
 * Calls OllamaChatCompletionsClient directly rather than through
 * OllamaJobAnalysisClient. This is deliberate, not a fidelity
 * shortcut: OllamaJobAnalysisClient's GeneratesJobAnalysis contract
 * only returns JobAnalysisProviderResponse (provider/model/
 * structuredContent) — the same shape OpenAIJobAnalysisClient returns
 * — and is intentionally left unmodified. finish_reason/usage, needed
 * for this harness's quality-evaluation reporting, live on
 * OllamaChatCompletionsResult instead. Calling the transport directly
 * still sends the exact same request OllamaJobAnalysisClient would:
 * same prompt, same schema, same schema name ('job_analysis'), same
 * output-token budget (OLLAMA_JOB_ANALYSIS_MAX_OUTPUT_TOKENS below,
 * which must track OllamaJobAnalysisClient::MAX_OUTPUT_TOKENS).
 *
 * Deliberately does NOT call GenerateJobAnalysis (which would persist
 * a JobAnalysis + Findings + Evidence tree) — this harness calls the
 * transport -> JobAnalysisResponseValidator -> EvidenceExcerptVerifier
 * directly, so each stage's outcome can be reported independently and
 * so this check never writes a JobAnalysis record anywhere. The only
 * persistence involved at all is jobAnalysisCorpusPosting()'s
 * JobPosting::factory()->create() call — the same corpus-fixture
 * helper JobAnalysisLiveCorpusTest.php already uses, into the same
 * RefreshDatabase-wrapped, :memory: sqlite test database (per
 * phpunit.xml's DB_DATABASE=:memory:) — never the real dev database,
 * and rolled back automatically when the test ends.
 *
 * The full decoded structuredContent is printed to STDERR (not logged
 * through the application's normal Log facade, and not persisted
 * anywhere) purely for this run's human inspection.
 *
 * A transport failure fails this test loudly — that's the thing this
 * harness exists to prove works. A JobAnalysisResponseValidator or
 * EvidenceExcerptVerifier failure does NOT fail the test — that's a
 * signal about this specific model's output quality on this specific
 * posting, not about whether the Ollama transport integration works,
 * and is exactly the kind of signal this harness exists to surface for
 * human review rather than assert on.
 */
const OLLAMA_JOB_ANALYSIS_MAX_OUTPUT_TOKENS = 8192; // must track OllamaJobAnalysisClient::MAX_OUTPUT_TOKENS

beforeEach(function () {
    if (blank(config('services.ollama.base_url'))) {
        $this->markTestSkipped('No OLLAMA_BASE_URL configured — skipping live Ollama Job Analysis check.');
    }
});

it('runs the real JobAnalysis prompt/schema through Ollama and reports every pipeline stage', function () {
    // Defaults to Pearly (this file's original fixture) — override for a
    // one-off diagnostic run against a different corpus posting, e.g.
    // OLLAMA_LIVE_JOB_ANALYSIS_POSTING=Rootstock. Deliberately a single
    // env var read here, not a config() entry or a multi-posting runner
    // — this stays a one-posting-at-a-time diagnostic tool.
    $postingPrefix = getenv('OLLAMA_LIVE_JOB_ANALYSIS_POSTING') ?: 'Pearly';
    $posting = jobAnalysisCorpusPosting($postingPrefix);

    $reasoningEffort = getenv('OLLAMA_LIVE_REASONING_EFFORT') ?: null;

    $transport = new OllamaChatCompletionsClient;
    $prompt = new JobAnalysisPromptV2;
    $validator = new JobAnalysisResponseValidator;
    $evidenceVerifier = new EvidenceExcerptVerifier;

    $report = [
        'posting' => $postingPrefix,
        'configured_model' => config('services.ollama.model'),
        'returned_model' => null,
        'reasoning_effort_requested' => $reasoningEffort,
        'elapsed_seconds' => null,
        'finish_reason' => null,
        'prompt_tokens' => null,
        'completion_tokens' => null,
        'total_tokens' => null,
        'finding_count' => null,
        'validator_passed' => null,
        'validator_error' => null,
        'evidence_verifier_passed' => null,
        'evidence_verifier_error' => null,
    ];

    $start = microtime(true);

    try {
        $result = $transport->call(
            baseUrl: (string) config('services.ollama.base_url'),
            model: (string) config('services.ollama.model'),
            systemPrompt: $prompt->systemPrompt(),
            userPrompt: $prompt->userPrompt($posting),
            schema: $prompt->jsonSchema(),
            schemaName: 'job_analysis',
            maxOutputTokens: OLLAMA_JOB_ANALYSIS_MAX_OUTPUT_TOKENS,
            timeoutSeconds: (int) config('services.ollama.timeout'),
            logPrefix: 'JobAnalysis generation (Ollama live check)',
            reasoningEffort: $reasoningEffort,
        );
    } catch (OllamaChatCompletionsException $e) {
        // Diagnostic metadata OllamaChatCompletionsException now carries
        // on a content-extraction failure (e.g. finish_reason:length) —
        // null for failure modes that never reached a decoded response
        // body (connection errors, non-2xx responses). Reported, never
        // used to retry or repair anything — this harness still fails
        // the test on any transport exception, unchanged.
        $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
        $report['returned_model'] = $e->model;
        $report['finish_reason'] = $e->finishReason;
        $report['prompt_tokens'] = $e->usage['prompt_tokens'] ?? null;
        $report['completion_tokens'] = $e->usage['completion_tokens'] ?? null;
        $report['total_tokens'] = $e->usage['total_tokens'] ?? null;
        printReport($report);
        throw $e;
    }

    $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
    $report['returned_model'] = $result->model;
    $report['finish_reason'] = $result->finishReason;
    $report['prompt_tokens'] = $result->usage['prompt_tokens'] ?? null;
    $report['completion_tokens'] = $result->usage['completion_tokens'] ?? null;
    $report['total_tokens'] = $result->usage['total_tokens'] ?? null;

    $validated = null;

    try {
        $validated = $validator->validate($result->structuredContent);
        $report['validator_passed'] = true;
        $report['finding_count'] = count($validated['findings'] ?? []);
    } catch (InvalidJobAnalysisResponseException $e) {
        $report['validator_passed'] = false;
        $report['validator_error'] = $e->getMessage();
    }

    if ($validated !== null) {
        try {
            $evidenceVerifier->verify($validated, $posting->description);
            $report['evidence_verifier_passed'] = true;
        } catch (InvalidJobAnalysisResponseException $e) {
            $report['evidence_verifier_passed'] = false;
            $report['evidence_verifier_error'] = $e->getMessage();
        }
    }

    printReport($report);
    printStructuredContent($result->structuredContent);

    // Only the transport/decode step is asserted on — validator and
    // evidence-verifier outcomes are reported, not asserted, per this
    // file's docblock.
    expect($result->structuredContent)->not->toBeEmpty();
});

/**
 * @param  array<string, mixed>  $report
 */
function printReport(array $report): void
{
    fwrite(STDERR, "\n".'=== Ollama Job Analysis live check: report ==='."\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

/**
 * @param  array<string, mixed>  $structuredContent
 */
function printStructuredContent(array $structuredContent): void
{
    fwrite(STDERR, "\n".'=== Ollama Job Analysis live check: structured content ==='."\n".json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
}
