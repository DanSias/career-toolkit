<?php

use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\EvidenceExcerptVerifier;
use App\Support\JobAnalysis\JobAnalysisResponseValidator;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV2;
use App\Support\JobAnalysis\Providers\OpenAIJobAnalysisClient;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * Opt-in, real-provider check that captures the same diagnostic depth
 * for the OpenAI JobAnalysis path that
 * tests/Llm/OllamaJobAnalysisLiveTest.php captures for Ollama, on the
 * identical Pearly posting — built for a controlled, same-input
 * comparison between the two providers. Lives outside phpunit.xml's
 * default testsuites via tests/Pest.php's `->in('Llm')` registration
 * (same mechanism as JobAnalysisLiveCorpusTest.php and
 * OllamaJobAnalysisLiveTest.php), so it never runs as part of
 * `php artisan test`. Run explicitly, by this exact file path only:
 *
 *     vendor/bin/pest tests/Llm/OpenAIJobAnalysisLiveTest.php
 *
 * Uses the existing OpenAIJobAnalysisClient directly (unmodified) —
 * unlike the Ollama harness, no transport bypass is needed here:
 * OpenAIJobAnalysisClient already returns JobAnalysisProviderResponse
 * with the actual returned model. It's also already the real,
 * container-default-bound production implementation of
 * GeneratesJobAnalysis; calling it directly (rather than through
 * GenerateJobAnalysis) is only to skip GenerateJobAnalysis's
 * persistence step, matching OllamaJobAnalysisLiveTest.php's
 * no-persistence design for a structurally comparable pair of runs.
 *
 * Token usage is captured via a test-local Log::listen() on the
 * existing "...: OpenAI usage." log line OpenAIResponsesApiClient
 * already emits — no production DTO/transport change needed (contrast
 * with OllamaChatCompletionsResult, which needed widening because
 * Ollama's finish_reason had no equivalent existing log capture point
 * for it; OpenAI's Responses API has no finish_reason concept at all,
 * since the transport already asserts response status == "completed"
 * or throws before returning).
 *
 * Runs JobAnalysisResponseValidator and EvidenceExcerptVerifier
 * directly (not through GenerateJobAnalysis) — same reasoning as the
 * Ollama harness: each stage's outcome is reported independently, and
 * this check never writes a JobAnalysis record anywhere. The only
 * persistence involved at all is jobAnalysisCorpusPosting()'s
 * JobPosting::factory()->create() call, into the same
 * RefreshDatabase-wrapped, :memory: sqlite test database — never the
 * real dev database, rolled back automatically when the test ends.
 *
 * The full decoded structuredContent is printed to STDERR (not logged
 * through the application's normal Log facade, and not persisted
 * anywhere) purely for this run's human inspection.
 *
 * A provider/transport failure fails this test loudly. A
 * JobAnalysisResponseValidator or EvidenceExcerptVerifier failure does
 * NOT fail the test — see OllamaJobAnalysisLiveTest.php's docblock for
 * why.
 */
beforeEach(function () {
    if (blank(config('services.openai.key'))) {
        $this->markTestSkipped('No OPENAI_API_KEY configured — skipping live OpenAI Job Analysis check.');
    }
});

it('runs the real JobAnalysis prompt/schema through OpenAI and reports every pipeline stage', function () {
    $posting = jobAnalysisCorpusPosting('Pearly');

    $client = new OpenAIJobAnalysisClient(
        apiKey: (string) config('services.openai.key'),
        model: (string) config('services.openai.model'),
    );

    $prompt = new JobAnalysisPromptV2;
    $validator = new JobAnalysisResponseValidator;
    $evidenceVerifier = new EvidenceExcerptVerifier;

    $report = [
        'configured_model' => config('services.openai.model'),
        'returned_model' => null,
        'elapsed_seconds' => null,
        'input_tokens' => null,
        'output_tokens' => null,
        'total_tokens' => null,
        'finding_count' => null,
        'validator_passed' => null,
        'validator_error' => null,
        'evidence_verifier_passed' => null,
        'evidence_verifier_error' => null,
    ];

    // OpenAIResponsesApiClient::call() already logs usage via
    // Log::info("...: OpenAI usage.", [...]) — captured here rather
    // than widening OpenAIResponsesApiResult, per this run's scope.
    Log::listen(function (MessageLogged $event) use (&$report) {
        if (str_contains($event->message, 'OpenAI usage.')) {
            $report['input_tokens'] = $event->context['input_tokens'] ?? null;
            $report['output_tokens'] = $event->context['output_tokens'] ?? null;
            $report['total_tokens'] = $event->context['total_tokens'] ?? null;
        }
    });

    $start = microtime(true);

    try {
        $providerResponse = $client->generate(
            $prompt->systemPrompt(),
            $prompt->userPrompt($posting),
            $prompt->jsonSchema(),
        );
    } catch (JobAnalysisProviderException $e) {
        $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
        printOpenAIReport($report);
        throw $e;
    }

    $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
    $report['returned_model'] = $providerResponse->model;

    $validated = null;

    try {
        $validated = $validator->validate($providerResponse->structuredContent);
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

    printOpenAIReport($report);
    printOpenAIStructuredContent($providerResponse->structuredContent);

    // Only the transport/decode step is asserted on — validator and
    // evidence-verifier outcomes are reported, not asserted, per this
    // file's docblock.
    expect($providerResponse->structuredContent)->not->toBeEmpty();
});

/**
 * @param  array<string, mixed>  $report
 */
function printOpenAIReport(array $report): void
{
    fwrite(STDERR, "\n".'=== OpenAI Job Analysis live check: report ==='."\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

/**
 * @param  array<string, mixed>  $structuredContent
 */
function printOpenAIStructuredContent(array $structuredContent): void
{
    fwrite(STDERR, "\n".'=== OpenAI Job Analysis live check: structured content ==='."\n".json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
}
