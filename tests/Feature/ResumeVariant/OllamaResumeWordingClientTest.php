<?php

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\ResumeVariant\Providers\OllamaResumeWordingClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Mocked against a hand-built Chat Completions response fixture, same
 * convention as tests/Feature/ResumeVariant/OllamaResumeSelectionClientTest.php
 * — NOT proof that a real Ollama server returns exactly this envelope.
 *
 * Deliberately does NOT re-cover request-independent transport
 * mechanics already proven by OllamaChatCompletionsClientTest.php
 * (finish_reason/usage exposure and enrichment, malformed-JSON/
 * no-choices/no-content fail-closed behavior, retry-count behavior on
 * 4xx/429/500, connection-failure wrapping) — that behavior lives
 * entirely in the shared, unmodified OllamaChatCompletionsClient and
 * doesn't change per calling client. This file covers only what's
 * specific to OllamaResumeWordingClient: the request shape it builds,
 * and that a transport failure becomes
 * ResumeGenerationProviderException.
 */
beforeEach(function () {
    Sleep::fake(); // retry backoff should not actually slow the test suite down
});

function ollamaResumeWordingClient(string $baseUrl = 'http://ollama.test:11434', int $timeoutSeconds = 900): OllamaResumeWordingClient
{
    return new OllamaResumeWordingClient(baseUrl: $baseUrl, model: 'qwen3.8:27b-test', timeoutSeconds: $timeoutSeconds);
}

/**
 * @param  array<string, mixed>  $structuredContent
 * @return array<string, mixed>
 */
function fakeResumeWordingChatCompletionsBody(array $structuredContent, ?string $model = 'qwen3.8:27b-test'): array
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
        'usage' => ['prompt_tokens' => 32000, 'completion_tokens' => 900, 'total_tokens' => 32900],
    ];
}

/**
 * A representative Resume-Wording-shaped schema fixture — not the real
 * dynamic-enum schema ResumeWordingPromptV1::jsonSchema() builds, just
 * enough structure to prove OllamaResumeWordingClient passes whatever
 * schema it's given through to the transport unmodified.
 *
 * @return array<string, mixed>
 */
function fakeResumeWordingSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string'],
            'experience' => ['type' => 'array'],
            'selected_projects' => ['type' => 'array'],
        ],
        'required' => ['summary', 'experience', 'selected_projects'],
        'additionalProperties' => false,
    ];
}

it('implements GeneratesResumeWording', function () {
    expect(ollamaResumeWordingClient())->toBeInstanceOf(GeneratesResumeWording::class);
});

it('posts to {base_url}/v1/chat/completions with the configured model, unchanged messages, resume_wording schema name, the supplied schema unchanged, and max_tokens 16000', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeWordingChatCompletionsBody(['summary' => 'x'])),
    ]);

    $schema = fakeResumeWordingSchema();

    ollamaResumeWordingClient()->generate('system prompt text', 'user prompt text', $schema);

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
            && $request['response_format']['json_schema']['name'] === 'resume_wording'
            && $request['response_format']['json_schema']['schema'] === $schema
            && $request['response_format']['json_schema']['strict'] === true
            && ! $request->hasHeader('Authorization');
    });
});

it('never sends a request to OpenAI', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeWordingChatCompletionsBody(['summary' => 'x'])),
    ]);

    ollamaResumeWordingClient()->generate('system', 'user', fakeResumeWordingSchema());

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai.com'));
});

it('reports the provider as ollama and preserves the actual model echoed back, not the configured one', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeWordingChatCompletionsBody(['summary' => 'x'], model: 'qwen3.8:27b-q4_k_m')),
    ]);

    $response = ollamaResumeWordingClient()->generate('system', 'user', fakeResumeWordingSchema());

    expect($response->provider)->toBe('ollama')
        ->and($response->model)->toBe('qwen3.8:27b-q4_k_m')
        ->and($response->generatedBy())->toBe('ollama:qwen3.8:27b-q4_k_m');
});

it('returns the decoded structured content unchanged for downstream ResumeWordingResponseValidator to validate', function () {
    $structuredContent = [
        'summary' => 'A senior full-stack developer.',
        'experience' => [
            ['role_id' => 1, 'bullets' => [['bullet_group_index' => 0, 'text' => 'Built a thing.']]],
        ],
        'selected_projects' => [
            ['project_id' => 14, 'text' => 'Built another thing.'],
        ],
    ];

    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeWordingChatCompletionsBody($structuredContent)),
    ]);

    $response = ollamaResumeWordingClient()->generate('system', 'user', fakeResumeWordingSchema());

    expect($response->structuredContent)->toBe($structuredContent);
});

it('passes the configured timeout through without altering the request shape', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeResumeWordingChatCompletionsBody(['summary' => 'x'])),
    ]);

    $response = ollamaResumeWordingClient(timeoutSeconds: 45)->generate('system', 'user', fakeResumeWordingSchema());

    expect($response->structuredContent)->toBe(['summary' => 'x']);
    Http::assertSentCount(1);
});

it('throws ResumeGenerationProviderException when configured with no base URL', function () {
    ollamaResumeWordingClient(baseUrl: '')->generate('system', 'user', fakeResumeWordingSchema());
})->throws(ResumeGenerationProviderException::class);

it('wraps a transport failure as ResumeGenerationProviderException — the same path a malformed-JSON or finish_reason=length failure takes', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(['error' => 'server error'], 500),
    ]);

    ollamaResumeWordingClient()->generate('system', 'user', fakeResumeWordingSchema());
})->throws(ResumeGenerationProviderException::class, 'Ollama request failed with HTTP status 500.');
