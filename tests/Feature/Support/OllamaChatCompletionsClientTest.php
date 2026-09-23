<?php

use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\OllamaChatCompletionsResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Exercises OllamaChatCompletionsClient directly (rather than through
 * OllamaJobAnalysisClient, which deliberately doesn't surface
 * finish_reason/usage) — covers the OllamaChatCompletionsResult fields
 * added specifically for tests/Llm/OllamaJobAnalysisLiveTest.php's
 * reporting needs, the optional reasoning_effort passthrough, and the
 * diagnostic metadata OllamaChatCompletionsException now carries on a
 * content-extraction failure. OllamaJobAnalysisClientTest.php already
 * covers the request-shape/retry/error-handling behavior this class
 * shares with that call path; not repeated here.
 */
beforeEach(function () {
    Sleep::fake();
});

function callOllamaTransport(array $body, ?string $reasoningEffort = null): OllamaChatCompletionsResult
{
    Http::fake(['ollama.test:11434/*' => Http::response($body)]);

    return (new OllamaChatCompletionsClient)->call(
        baseUrl: 'http://ollama.test:11434',
        model: 'qwen3.8:27b-test',
        systemPrompt: 'system',
        userPrompt: 'user',
        schema: ['type' => 'object'],
        schemaName: 'job_analysis',
        maxOutputTokens: 8192,
        timeoutSeconds: 300,
        logPrefix: 'test',
        reasoningEffort: $reasoningEffort,
    );
}

it('exposes finish_reason and usage on the result', function () {
    $result = callOllamaTransport([
        'model' => 'qwen3.8:27b',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => json_encode(['ok' => true])],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
    ]);

    expect($result->finishReason)->toBe('stop')
        ->and($result->usage)->toBe(['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150]);
});

it('defaults finish_reason and usage to null when absent from the response body', function () {
    $result = callOllamaTransport([
        'model' => 'qwen3.8:27b',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => json_encode(['ok' => true])],
            ],
        ],
    ]);

    expect($result->finishReason)->toBeNull()
        ->and($result->usage)->toBeNull();
});

it('still throws before constructing a result when finish_reason is length', function () {
    callOllamaTransport([
        'model' => 'qwen3.8:27b',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => '{"incomple'],
                'finish_reason' => 'length',
            ],
        ],
    ]);
})->throws(OllamaChatCompletionsException::class, 'Ollama response was truncated');

it('does not omit the reasoning_effort request field when the request body is otherwise unusable — reasoning control', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response([
            'model' => 'qwen3.8:27b',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode(['ok' => true])], 'finish_reason' => 'stop'],
            ],
        ]),
    ]);

    (new OllamaChatCompletionsClient)->call(
        baseUrl: 'http://ollama.test:11434',
        model: 'qwen3.8:27b-test',
        systemPrompt: 'system',
        userPrompt: 'user',
        schema: ['type' => 'object'],
        schemaName: 'job_analysis',
        maxOutputTokens: 8192,
        timeoutSeconds: 300,
        logPrefix: 'test',
        // reasoningEffort omitted entirely — the default.
    );

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ! array_key_exists('reasoning_effort', $data)
            && ! array_key_exists('reasoning', $data)
            && ! array_key_exists('think', $data)
            && $data['model'] === 'qwen3.8:27b-test'
            && $data['max_tokens'] === 8192
            && $data['response_format']['type'] === 'json_schema'
            && $data['response_format']['json_schema']['strict'] === true;
    });
});

it('sends exactly one reasoning_effort field, and nothing else new, when reasoningEffort is "none"', function () {
    callOllamaTransport(
        ['model' => 'qwen3.8:27b', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode(['ok' => true])], 'finish_reason' => 'stop']]],
        reasoningEffort: 'none',
    );

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $data['reasoning_effort'] === 'none'
            && ! array_key_exists('reasoning', $data)
            && ! array_key_exists('think', $data)
            // Everything else stays exactly as an omitted-option request would build it.
            && $data['model'] === 'qwen3.8:27b-test'
            && $data['max_tokens'] === 8192
            && $data['response_format']['type'] === 'json_schema'
            && $data['response_format']['json_schema']['name'] === 'job_analysis'
            && $data['response_format']['json_schema']['strict'] === true;
    });
});

it('preserves model, finish_reason, and usage on the exception when a length-truncated response also carries usage', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response([
            'model' => 'qwen3.8:27b-q4_k_m',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"incomple'], 'finish_reason' => 'length'],
            ],
            'usage' => ['prompt_tokens' => 3910, 'completion_tokens' => 8192, 'total_tokens' => 12102],
        ]),
    ]);

    try {
        (new OllamaChatCompletionsClient)->call(
            baseUrl: 'http://ollama.test:11434',
            model: 'qwen3.8:27b-test',
            systemPrompt: 'system',
            userPrompt: 'user',
            schema: ['type' => 'object'],
            schemaName: 'job_analysis',
            maxOutputTokens: 8192,
            timeoutSeconds: 300,
            logPrefix: 'test',
        );

        $this->fail('Expected OllamaChatCompletionsException to be thrown.');
    } catch (OllamaChatCompletionsException $e) {
        expect($e->getMessage())->toContain('truncated')
            ->and($e->model)->toBe('qwen3.8:27b-q4_k_m')
            ->and($e->finishReason)->toBe('length')
            ->and($e->usage)->toBe(['prompt_tokens' => 3910, 'completion_tokens' => 8192, 'total_tokens' => 12102]);
    }
});

it('leaves the new exception metadata null when the response never provided it, e.g. a connection failure', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused.');
    });

    try {
        (new OllamaChatCompletionsClient)->call(
            baseUrl: 'http://ollama.test:11434',
            model: 'qwen3.8:27b-test',
            systemPrompt: 'system',
            userPrompt: 'user',
            schema: ['type' => 'object'],
            schemaName: 'job_analysis',
            maxOutputTokens: 8192,
            timeoutSeconds: 300,
            logPrefix: 'test',
        );

        $this->fail('Expected OllamaChatCompletionsException to be thrown.');
    } catch (OllamaChatCompletionsException $e) {
        expect($e->model)->toBeNull()
            ->and($e->finishReason)->toBeNull()
            ->and($e->usage)->toBeNull();
    }
});

it('does not return a result at all when content is truncated — no partial structured content is ever decoded', function () {
    Http::fake([
        'ollama.test:11434/*' => Http::response([
            'model' => 'qwen3.8:27b',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"findings": [{"id": 1, "incomplete_trailing_ga'], 'finish_reason' => 'length'],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 8192, 'total_tokens' => 8292],
        ]),
    ]);

    $threw = false;

    try {
        (new OllamaChatCompletionsClient)->call(
            baseUrl: 'http://ollama.test:11434',
            model: 'qwen3.8:27b-test',
            systemPrompt: 'system',
            userPrompt: 'user',
            schema: ['type' => 'object'],
            schemaName: 'job_analysis',
            maxOutputTokens: 8192,
            timeoutSeconds: 300,
            logPrefix: 'test',
        );
    } catch (OllamaChatCompletionsException $e) {
        $threw = true;
        // The exception message is a fixed, safe string — never the raw
        // (possibly truncated/malformed) content itself.
        expect($e->getMessage())->not->toContain('incomplete_trailing_ga')
            ->and($e->finishReason)->toBe('length')
            ->and($e->usage)->toBe(['prompt_tokens' => 100, 'completion_tokens' => 8192, 'total_tokens' => 8292]);
    }

    expect($threw)->toBeTrue();
});
