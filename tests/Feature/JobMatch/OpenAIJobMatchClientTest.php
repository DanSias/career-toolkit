<?php

use App\Exceptions\JobMatchProviderException;
use App\Support\JobMatch\Providers\OpenAIJobMatchClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake(); // retry backoff should not actually slow the test suite down
});

function openAIJobMatchClient(string $apiKey = 'test-key'): OpenAIJobMatchClient
{
    return new OpenAIJobMatchClient(apiKey: $apiKey, model: 'gpt-5.6-test');
}

function fakeJobMatchResponsesApiBody(array $structuredContent, ?string $model = 'gpt-5.6-test'): array
{
    return [
        'id' => 'resp_test',
        'object' => 'response',
        'status' => 'completed',
        'model' => $model,
        'output' => [
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

it('sends the configured model, an Authorization bearer header, and the job_match schema name', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeJobMatchResponsesApiBody(['ok' => true])),
    ]);

    openAIJobMatchClient(apiKey: 'sk-test-secret')->generate('system', 'user', ['type' => 'object']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer sk-test-secret')
            && $request['model'] === 'gpt-5.6-test'
            && $request['text']['format']['name'] === 'job_match'
            && $request['text']['format']['strict'] === true
            && ! array_key_exists('temperature', $request->data())
            && ! array_key_exists('top_p', $request->data());
    });
});

it('parses structured content from the message output_text part', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeJobMatchResponsesApiBody(['findings' => []])),
    ]);

    $response = openAIJobMatchClient()->generate('system', 'user', ['type' => 'object']);

    expect($response->provider)->toBe('openai')
        ->and($response->model)->toBe('gpt-5.6-test')
        ->and($response->structuredContent)->toBe(['findings' => []])
        ->and($response->generatedBy())->toBe('openai:gpt-5.6-test');
});

it('throws when configured with no API key', function () {
    openAIJobMatchClient(apiKey: '')->generate('system', 'user', ['type' => 'object']);
})->throws(JobMatchProviderException::class);

it('throws on a refusal content part', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'status' => 'completed',
            'model' => 'gpt-5.6-test',
            'output' => [
                ['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'cannot help']]],
            ],
        ]),
    ]);

    openAIJobMatchClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobMatchProviderException::class);

it('throws when the response content is not valid JSON', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'status' => 'completed',
            'model' => 'gpt-5.6-test',
            'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'not { valid json']]],
            ],
        ]),
    ]);

    openAIJobMatchClient()->generate('system', 'user', ['type' => 'object']);
})->throws(JobMatchProviderException::class);

it('retries a transient server error and succeeds on a later attempt', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['error' => 'server error'], 500)
            ->push(fakeJobMatchResponsesApiBody(['ok' => true])),
    ]);

    $response = openAIJobMatchClient()->generate('system', 'user', ['type' => 'object']);

    expect($response->structuredContent)->toBe(['ok' => true]);
    Http::assertSentCount(2);
});

it('does not retry an ordinary 4xx request failure', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => 'invalid api key'], 401),
    ]);

    try {
        openAIJobMatchClient()->generate('system', 'user', ['type' => 'object']);
    } catch (JobMatchProviderException) {
        // expected
    }

    Http::assertSentCount(1);
});

it('gives up after exhausting retries on a persistent server error', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => 'server error'], 500),
    ]);

    try {
        openAIJobMatchClient()->generate('system', 'user', ['type' => 'object']);
    } catch (JobMatchProviderException) {
        // expected
    }

    Http::assertSentCount(3);
});
