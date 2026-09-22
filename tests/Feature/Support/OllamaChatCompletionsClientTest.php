<?php

use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\OllamaChatCompletionsResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Exercises OllamaChatCompletionsClient directly (rather than through
 * OllamaJobAnalysisClient, which deliberately doesn't surface
 * finish_reason/usage) — covers the OllamaChatCompletionsResult fields
 * added specifically for tests/Llm/OllamaJobAnalysisLiveTest.php's
 * reporting needs. OllamaJobAnalysisClientTest.php already covers the
 * request-shape/retry/error-handling behavior this class shares with
 * that call path; not repeated here.
 */
beforeEach(function () {
    Sleep::fake();
});

function callOllamaTransport(array $body): OllamaChatCompletionsResult
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
