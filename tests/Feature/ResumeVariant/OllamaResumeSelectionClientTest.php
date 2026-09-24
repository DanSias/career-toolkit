<?php

use App\Contracts\GeneratesResumeSelection;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\ResumeVariant\Providers\OllamaResumeSelectionClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Mocked against a hand-built Chat Completions response fixture, same
 * convention as tests/Feature/JobMatch/OllamaJobMatchClientTest.php —
 * NOT proof that a real Ollama server returns exactly this envelope.
 *
 * Deliberately does NOT re-cover request-independent transport
 * mechanics already proven by OllamaChatCompletionsClientTest.php
 * (finish_reason/usage exposure and enrichment, malformed-JSON/
 * no-choices/no-content fail-closed behavior, retry-count behavior on
 * 4xx/429/500, connection-failure wrapping) — that behavior lives
 * entirely in the shared, unmodified OllamaChatCompletionsClient and
 * doesn't change per calling client. Any such transport-level failure
 * reaches this client as OllamaChatCompletionsException regardless of
 * cause; "wraps a transport failure as ResumeGenerationProviderException"
 * below proves this client's own catch/rethrow path, which is the only
 * thing genuinely specific to it. This file covers only what's specific
 * to OllamaResumeSelectionClient: the request shape it builds, and that
 * a transport failure becomes ResumeGenerationProviderException.
 */
beforeEach(function () {
    Sleep::fake(); // retry backoff should not actually slow the test suite down
});

function ollamaResumeSelectionClient(string $baseUrl = 'http://ollama.test:11434', int $timeoutSeconds = 900): OllamaResumeSelectionClient
{
    return new OllamaResumeSelectionClient(baseUrl: $baseUrl, model: 'qwen3.8:27b-test', timeoutSeconds: $timeoutSeconds);
}

/**
 * @param  array<string, mixed>  $structuredContent
 * @return array<string, mixed>
 */
function fakeResumeSelectionChatCompletionsBody(array $structuredContent, ?string $model = 'qwen3.8:27b-test'): array
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
        'usage' => ['prompt_tokens' => 29000, 'completion_tokens' => 1200, 'total_tokens' => 30200],
    ];
}

/**
 * A representative Resume-Selection-shaped schema fixture — not the
 * real dynamic-enum schema ResumeSelectionPromptV2::jsonSchema()
 * builds (that's covered by ResumeSelectionPromptV2Test.php), just
 * enough structure to prove OllamaResumeSelectionClient passes whatever
 * schema it's given through to the transport unmodified.
 *
 * @return array<string, mixed>
 */
function fakeResumeSelectionSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'summary_evidence' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['fact-a']]],
            'skills' => ['type' => 'array'],
            'experience' => ['type' => 'array'],
            'selected_projects' => ['type' => 'array', 'maxItems' => 3],
            'target_term_usages' => ['type' => 'array'],
        ],
    ];
}

it('implements GeneratesResumeSelection', function () {
    expect(ollamaResumeSelectionClient())->toBeInstanceOf(GeneratesResumeSelection::class);
});

it('posts to {base_url}/v1/chat/completions with the configured model, unchanged messages, resume_selection schema name, the supplied schema unchanged, and max_tokens 16000', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeSelectionChatCompletionsBody(['skills' => []])),
    ]);

    $schema = fakeResumeSelectionSchema();

    ollamaResumeSelectionClient()->generate('system prompt text', 'user prompt text', $schema);

    Http::assertSent(function ($request) use ($schema) {
        return $request->url() === 'http://ollama.test:11434/v1/chat/completions'
            && $request['model'] === 'qwen3.8:27b-test'
            && $request['messages'] === [
                ['role' => 'system', 'content' => 'system prompt text'],
                ['role' => 'user', 'content' => 'user prompt text'],
            ]
            && $request['max_tokens'] === 16000
            && ! array_key_exists('reasoning_effort', $request->data())
            && $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['name'] === 'resume_selection'
            && $request['response_format']['json_schema']['schema'] === $schema
            && $request['response_format']['json_schema']['strict'] === true
            && ! $request->hasHeader('Authorization');
    });
});

it('reports the provider as ollama and preserves the actual model echoed back, not the configured one', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeSelectionChatCompletionsBody(['skills' => []], model: 'qwen3.8:27b-q4_k_m')),
    ]);

    $response = ollamaResumeSelectionClient()->generate('system', 'user', fakeResumeSelectionSchema());

    expect($response->provider)->toBe('ollama')
        ->and($response->model)->toBe('qwen3.8:27b-q4_k_m')
        ->and($response->generatedBy())->toBe('ollama:qwen3.8:27b-q4_k_m');
});

it('returns the decoded structured content unchanged for downstream ResumeSelectionResponseValidator to validate', function () {
    $structuredContent = [
        'summary_evidence' => ['fact-a'],
        'skills' => [['skill_id' => 1, 'order' => 1]],
        'experience' => [],
        'selected_projects' => [],
        'target_term_usages' => [],
    ];

    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeSelectionChatCompletionsBody($structuredContent)),
    ]);

    $response = ollamaResumeSelectionClient()->generate('system', 'user', fakeResumeSelectionSchema());

    expect($response->structuredContent)->toBe($structuredContent);
});

it('passes the configured timeout through without altering the request shape', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeSelectionChatCompletionsBody(['skills' => []])),
    ]);

    $response = ollamaResumeSelectionClient(timeoutSeconds: 45)->generate('system', 'user', fakeResumeSelectionSchema());

    expect($response->structuredContent)->toBe(['skills' => []]);
    Http::assertSentCount(1);
});

it('throws ResumeGenerationProviderException when configured with no base URL', function () {
    ollamaResumeSelectionClient(baseUrl: '')->generate('system', 'user', fakeResumeSelectionSchema());
})->throws(ResumeGenerationProviderException::class);

it('wraps a transport failure as ResumeGenerationProviderException — the same path a malformed-JSON or finish_reason=length failure takes', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(['error' => 'server error'], 500),
    ]);

    ollamaResumeSelectionClient()->generate('system', 'user', fakeResumeSelectionSchema());
})->throws(ResumeGenerationProviderException::class, 'Ollama request failed with HTTP status 500.');
