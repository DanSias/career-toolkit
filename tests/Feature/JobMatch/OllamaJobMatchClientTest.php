<?php

use App\Contracts\GeneratesJobMatch;
use App\Exceptions\JobMatchProviderException;
use App\Support\JobMatch\Providers\OllamaJobMatchClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Mocked against a hand-built Chat Completions response fixture, same
 * convention as tests/Feature/JobAnalysis/OllamaJobAnalysisClientTest.php
 * — NOT proof that a real Ollama server returns exactly this envelope.
 * See the opt-in tests/Llm/OllamaJobMatchLiveTest.php for that.
 *
 * Deliberately does NOT re-cover request-independent transport mechanics
 * already proven by OllamaChatCompletionsClientTest.php (finish_reason/
 * usage exposure) and OllamaJobAnalysisClientTest.php (retry-count
 * behavior on 4xx/429/500, malformed-JSON/no-choices/no-content
 * fail-closed behavior, connection-failure wrapping) — that behavior
 * lives entirely in the shared, unmodified OllamaChatCompletionsClient
 * and doesn't change per calling client. This file covers only what's
 * specific to OllamaJobMatchClient: the request shape it builds, and
 * that a transport failure becomes JobMatchProviderException rather
 * than JobAnalysisProviderException.
 */
beforeEach(function () {
    Sleep::fake(); // retry backoff should not actually slow the test suite down
});

function ollamaJobMatchClient(string $baseUrl = 'http://ollama.test:11434', int $timeoutSeconds = 300): OllamaJobMatchClient
{
    return new OllamaJobMatchClient(baseUrl: $baseUrl, model: 'qwen3.8:27b-test', timeoutSeconds: $timeoutSeconds);
}

/**
 * @param  array<string, mixed>  $structuredContent
 * @return array<string, mixed>
 */
function fakeJobMatchChatCompletionsBody(array $structuredContent, ?string $model = 'qwen3.8:27b-test'): array
{
    return [
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode($structuredContent),
                ],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
    ];
}

/**
 * A representative JobMatch-shaped schema fixture — not the real
 * dynamic-enum schema JobMatchPromptV1::jsonSchema() builds (that's
 * covered by JobMatchPromptV1Test.php), just enough structure to prove
 * OllamaJobMatchClient passes whatever schema it's given through to the
 * transport unmodified.
 *
 * @return array<string, mixed>
 */
function fakeJobMatchSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'findings' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'job_analysis_finding_id' => ['type' => 'integer', 'enum' => [482, 501]],
                        'coverage' => ['type' => 'string', 'enum' => ['supported', 'partial', 'no_evidence', 'not_assessable']],
                    ],
                ],
            ],
        ],
    ];
}

it('implements GeneratesJobMatch', function () {
    expect(ollamaJobMatchClient())->toBeInstanceOf(GeneratesJobMatch::class);
});

it('posts to {base_url}/v1/chat/completions with the configured model, unchanged messages, job_match schema name, the supplied schema unchanged, and max_tokens 16000', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeJobMatchChatCompletionsBody(['findings' => []])),
    ]);

    $schema = fakeJobMatchSchema();

    ollamaJobMatchClient()->generate('system prompt text', 'user prompt text', $schema);

    Http::assertSent(function ($request) use ($schema) {
        return $request->url() === 'http://ollama.test:11434/v1/chat/completions'
            && $request['model'] === 'qwen3.8:27b-test'
            && $request['messages'] === [
                ['role' => 'system', 'content' => 'system prompt text'],
                ['role' => 'user', 'content' => 'user prompt text'],
            ]
            && $request['max_tokens'] === 16000
            && $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['name'] === 'job_match'
            && $request['response_format']['json_schema']['schema'] === $schema
            && $request['response_format']['json_schema']['strict'] === true
            && ! $request->hasHeader('Authorization');
    });
});

it('reports the provider as ollama and preserves the actual model echoed back, not the configured one', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeJobMatchChatCompletionsBody(['findings' => []], model: 'qwen3.8:27b-q4_k_m')),
    ]);

    $response = ollamaJobMatchClient()->generate('system', 'user', fakeJobMatchSchema());

    expect($response->provider)->toBe('ollama')
        ->and($response->model)->toBe('qwen3.8:27b-q4_k_m')
        ->and($response->generatedBy())->toBe('ollama:qwen3.8:27b-q4_k_m');
});

it('returns the decoded structured content unchanged for downstream JobMatchResponseValidator to validate', function () {
    $structuredContent = [
        'findings' => [
            [
                'job_analysis_finding_id' => 482,
                'coverage' => 'partial',
                'coverage_rationale' => 'Adjacent but not identical field of study.',
                'matches' => [],
                'education_matches' => [
                    ['education_id' => 3, 'relationship' => 'transferable', 'rationale' => 'Engineering Physics is quantitative/technical.'],
                ],
            ],
        ],
    ];

    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeJobMatchChatCompletionsBody($structuredContent)),
    ]);

    $response = ollamaJobMatchClient()->generate('system', 'user', fakeJobMatchSchema());

    expect($response->structuredContent)->toBe($structuredContent);
});

it('passes the configured timeout through without altering the request shape', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeJobMatchChatCompletionsBody(['findings' => []])),
    ]);

    $response = ollamaJobMatchClient(timeoutSeconds: 45)->generate('system', 'user', fakeJobMatchSchema());

    expect($response->structuredContent)->toBe(['findings' => []]);
    Http::assertSentCount(1);
});

it('throws JobMatchProviderException when configured with no base URL', function () {
    ollamaJobMatchClient(baseUrl: '')->generate('system', 'user', fakeJobMatchSchema());
})->throws(JobMatchProviderException::class);

it('wraps a transport failure as JobMatchProviderException, not JobAnalysisProviderException', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(['error' => 'server error'], 500),
    ]);

    ollamaJobMatchClient()->generate('system', 'user', fakeJobMatchSchema());
})->throws(JobMatchProviderException::class, 'Ollama request failed with HTTP status 500.');

it('preserves non-content provider diagnostics on a truncation failure, including this client\'s own 16000 max_tokens budget', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response([
            'model' => 'qwen3.8:27b-test',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"incomple'], 'finish_reason' => 'length'],
            ],
            'usage' => ['prompt_tokens' => 35030, 'completion_tokens' => 16000, 'total_tokens' => 51030],
        ]),
    ]);

    try {
        ollamaJobMatchClient(timeoutSeconds: 600)->generate('system', 'user', fakeJobMatchSchema());
        test()->fail('Expected JobMatchProviderException to be thrown.');
    } catch (JobMatchProviderException $e) {
        expect($e->diagnostics)->not->toBeNull()
            ->and($e->diagnostics->finishReason)->toBe('length')
            ->and($e->diagnostics->usage)->toBe(['prompt_tokens' => 35030, 'completion_tokens' => 16000, 'total_tokens' => 51030])
            ->and($e->diagnostics->maxOutputTokens)->toBe(16000)
            ->and($e->diagnostics->timeoutSeconds)->toBe(600);
    }
});
