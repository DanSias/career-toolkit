<?php

use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\Providers\OllamaJobAnalysisClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Mocked against a hand-built Chat Completions response fixture based
 * on documented OpenAI/Ollama Chat Completions behavior
 * (choices[0].message.content, response_format, finish_reason, usage
 * shaped as prompt_tokens/completion_tokens/total_tokens) — NOT proof
 * that a real Ollama server returns exactly this envelope. See the
 * opt-in tests/Llm/OllamaJobAnalysisLiveTest.php for that.
 */
beforeEach(function () {
    Sleep::fake(); // retry backoff should not actually slow the test suite down
});

function ollamaClient(string $baseUrl = 'http://ollama.test:11434', int $timeoutSeconds = 300): OllamaJobAnalysisClient
{
    return new OllamaJobAnalysisClient(baseUrl: $baseUrl, model: 'qwen3.8:27b-test', timeoutSeconds: $timeoutSeconds);
}

function fakeChatCompletionsBody(array $structuredContent, ?string $model = 'qwen3.8:27b-test', ?array $usage = null): array
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
        'usage' => $usage,
    ];
}

it('posts to {base_url}/v1/chat/completions with the configured model, messages, response_format, and max_tokens', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeChatCompletionsBody(['ok' => true])),
    ]);

    ollamaClient()->generate('system prompt text', 'user prompt text', ['type' => 'object', 'properties' => []]);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ollama.test:11434/v1/chat/completions'
            && $request['model'] === 'qwen3.8:27b-test'
            && $request['messages'] === [
                ['role' => 'system', 'content' => 'system prompt text'],
                ['role' => 'user', 'content' => 'user prompt text'],
            ]
            && $request['max_tokens'] === 8192
            && $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['name'] === 'job_analysis'
            && $request['response_format']['json_schema']['schema'] === ['type' => 'object', 'properties' => []]
            && $request['response_format']['json_schema']['strict'] === true
            && ! $request->hasHeader('Authorization');
    });
});

it('normalizes a base URL with a trailing slash to avoid a double slash in the endpoint', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeChatCompletionsBody(['ok' => true])),
    ]);

    ollamaClient(baseUrl: 'http://ollama.test:11434/')->generate('system', 'user', ['type' => 'object']);

    Http::assertSent(fn ($request) => $request->url() === 'http://ollama.test:11434/v1/chat/completions');
});

it('parses structured content from choices[0].message.content', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeChatCompletionsBody(['role_summary' => 'ok'])),
    ]);

    $response = ollamaClient()->generate('system', 'user', ['type' => 'object']);

    expect($response->provider)->toBe('ollama')
        ->and($response->model)->toBe('qwen3.8:27b-test')
        ->and($response->structuredContent)->toBe(['role_summary' => 'ok'])
        ->and($response->generatedBy())->toBe('ollama:qwen3.8:27b-test');
});

it('uses the actual model identifier echoed back by the server for generated_by, not the configured one', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeChatCompletionsBody(['ok' => true], model: 'qwen3.8:27b-q4_k_m')),
    ]);

    $response = ollamaClient()->generate('system', 'user', ['type' => 'object']);

    expect($response->model)->toBe('qwen3.8:27b-q4_k_m');
});

it('throws when configured with no base URL', function () {
    ollamaClient(baseUrl: '')->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class);

it('throws when the response has no choices', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response([
            'model' => 'qwen3.8:27b-test',
            'choices' => [],
        ]),
    ]);

    ollamaClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class, 'Ollama response had no choices.');

it('throws when choices[0] has no message content', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response([
            'model' => 'qwen3.8:27b-test',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant'], 'finish_reason' => 'stop'],
            ],
        ]),
    ]);

    ollamaClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class, 'Ollama response contained no message content.');

it('throws a truncation-specific error when finish_reason is length', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response([
            'model' => 'qwen3.8:27b-test',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"incomple'], 'finish_reason' => 'length'],
            ],
        ]),
    ]);

    ollamaClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class, 'Ollama response was truncated');

it('throws when the response content is not valid JSON', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response([
            'model' => 'qwen3.8:27b-test',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'not { valid json'], 'finish_reason' => 'stop'],
            ],
        ]),
    ]);

    ollamaClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class, 'Ollama response content was not valid JSON.');

it('does not retry an ordinary 4xx request failure', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(['error' => 'model not found'], 404),
    ]);

    try {
        ollamaClient()->generate('system', 'user', ['type' => 'object']);
    } catch (JobAnalysisProviderException) {
        // expected
    }

    Http::assertSentCount(1);
});

it('retries a transient server error and succeeds on a later attempt', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::sequence()
            ->push(['error' => 'server error'], 500)
            ->push(fakeChatCompletionsBody(['ok' => true])),
    ]);

    $response = ollamaClient()->generate('system', 'user', ['type' => 'object']);

    expect($response->structuredContent)->toBe(['ok' => true]);
    Http::assertSentCount(2);
});

it('retries a 429 rate-limit response', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429)
            ->push(fakeChatCompletionsBody(['ok' => true])),
    ]);

    ollamaClient()->generate('system', 'user', ['type' => 'object']);

    Http::assertSentCount(2);
});

it('gives up after exhausting retries on a persistent server error', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response(['error' => 'server error'], 500),
    ]);

    try {
        ollamaClient()->generate('system', 'user', ['type' => 'object']);
    } catch (JobAnalysisProviderException) {
        // expected
    }

    Http::assertSentCount(3);
});

it('wraps a connection failure as a JobAnalysisProviderException', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused.');
    });

    ollamaClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class, 'Ollama request failed: connection error.');

it('accepts a custom timeout without altering the request shape', function () {
    // Illuminate\Http\Client\Request (what Http::fake()/assertSent() sees)
    // doesn't expose the resolved client-level timeout option, so this
    // can't assert the timeout value took effect — only that a
    // non-default timeoutSeconds doesn't change anything else about the
    // request. Whether a configurable timeout is actually necessary for
    // real 27B-class local inference latency is a live-server question,
    // not a mocked one — see tests/Llm/OllamaJobAnalysisLiveTest.php.
    Http::fake([
        'ollama.test:11434/*' => Http::response(fakeChatCompletionsBody(['ok' => true])),
    ]);

    $response = ollamaClient(timeoutSeconds: 45)->generate('system', 'user', ['type' => 'object']);

    expect($response->structuredContent)->toBe(['ok' => true]);
    Http::assertSentCount(1);
});
