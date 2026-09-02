<?php

use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\Providers\OpenAIJobAnalysisClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake(); // retry backoff should not actually slow the test suite down
});

function openAIClient(string $apiKey = 'test-key'): OpenAIJobAnalysisClient
{
    return new OpenAIJobAnalysisClient(apiKey: $apiKey, model: 'gpt-5.6-test');
}

function fakeResponsesApiBody(array $structuredContent, ?string $model = 'gpt-5.6-test'): array
{
    return [
        'id' => 'resp_test',
        'object' => 'response',
        'status' => 'completed',
        'model' => $model,
        'output' => [
            // A reasoning item before the message item, to prove the
            // client scans for the right item rather than assuming
            // output[0] is always the message.
            ['type' => 'reasoning', 'id' => 'rsn_test'],
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => json_encode($structuredContent)],
                ],
            ],
        ],
    ];
}

it('sends the configured model and an Authorization bearer header, never as a raw request body secret', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeResponsesApiBody(['ok' => true])),
    ]);

    openAIClient(apiKey: 'sk-test-secret')->generate('system', 'user', ['type' => 'object']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer sk-test-secret')
            && $request['model'] === 'gpt-5.6-test'
            && $request['text']['format']['type'] === 'json_schema'
            && $request['text']['format']['strict'] === true
            && ! array_key_exists('temperature', $request->data())
            && ! array_key_exists('top_p', $request->data());
    });
});

it('parses structured content from the message output_text part regardless of item ordering', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeResponsesApiBody(['role_summary' => 'ok'])),
    ]);

    $response = openAIClient()->generate('system', 'user', ['type' => 'object']);

    expect($response->provider)->toBe('openai')
        ->and($response->model)->toBe('gpt-5.6-test')
        ->and($response->structuredContent)->toBe(['role_summary' => 'ok'])
        ->and($response->generatedBy())->toBe('openai:gpt-5.6-test');
});

it('uses the actual model identifier echoed back by the API for generated_by, not the configured one', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeResponsesApiBody(['ok' => true], model: 'gpt-5.6-test-2026-09-01')),
    ]);

    $response = openAIClient()->generate('system', 'user', ['type' => 'object']);

    expect($response->model)->toBe('gpt-5.6-test-2026-09-01');
});

it('throws when configured with no API key', function () {
    openAIClient(apiKey: '')->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class);

it('throws on a refusal content part', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'status' => 'completed',
            'model' => 'gpt-5.6-test',
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'refusal', 'refusal' => 'I cannot help with that.'],
                    ],
                ],
            ],
        ]),
    ]);

    openAIClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class);

it('throws when the response status is not completed', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'status' => 'incomplete',
            'model' => 'gpt-5.6-test',
            'output' => [],
        ]),
    ]);

    openAIClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class);

it('throws when the response content is not valid JSON', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeResponsesApiBodyWithRawText('not { valid json')),
    ]);

    openAIClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobAnalysisProviderException::class);

function fakeResponsesApiBodyWithRawText(string $rawText): array
{
    return [
        'status' => 'completed',
        'model' => 'gpt-5.6-test',
        'output' => [
            [
                'type' => 'message',
                'content' => [
                    ['type' => 'output_text', 'text' => $rawText],
                ],
            ],
        ],
    ];
}

it('retries a transient server error and succeeds on a later attempt', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['error' => 'server error'], 500)
            ->push(fakeResponsesApiBody(['ok' => true])),
    ]);

    $response = openAIClient()->generate('system', 'user', ['type' => 'object']);

    expect($response->structuredContent)->toBe(['ok' => true]);
    Http::assertSentCount(2);
});

it('retries a 429 rate-limit response', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429)
            ->push(fakeResponsesApiBody(['ok' => true])),
    ]);

    openAIClient()->generate('system', 'user', ['type' => 'object']);

    Http::assertSentCount(2);
});

it('does not retry an ordinary 4xx request failure', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => 'invalid api key'], 401),
    ]);

    try {
        openAIClient()->generate('system', 'user', ['type' => 'object']);
    } catch (JobAnalysisProviderException) {
        // expected
    }

    Http::assertSentCount(1);
});

it('gives up after exhausting retries on a persistent server error', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => 'server error'], 500),
    ]);

    try {
        openAIClient()->generate('system', 'user', ['type' => 'object']);
    } catch (JobAnalysisProviderException) {
        // expected
    }

    Http::assertSentCount(3);
});
