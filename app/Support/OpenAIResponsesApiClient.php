<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * The shared HTTP-transport mechanics for calling OpenAI's Responses
 * API with strict structured output — the call itself, bounded retry
 * on transient failures, and parsing the response envelope down to a
 * decoded structured-content array. Extracted once OpenAIJobAnalysisClient
 * and OpenAIJobMatchClient turned out to duplicate this almost entirely
 * (see docs/job-match-generation.md and docs/job-analysis-generation.md).
 *
 * Deliberately NOT a shared base class or a generic multi-provider
 * abstraction — this is the one piece of genuinely identical mechanics
 * (the raw API call shape), nothing more. Each concrete client still
 * owns its own domain exception type, its own *ProviderResponse DTO,
 * and its own schema/token-budget choices; only the transport plumbing
 * lives here.
 */
final class OpenAIResponsesApiClient
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    private const TIMEOUT_SECONDS = 120;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    /**
     * Only these are treated as transient/worth retrying: request
     * timeout, rate limiting, and server-side failures. An ordinary 4xx
     * (bad request, bad/missing API key, not found) is a
     * request/configuration problem a retry cannot fix.
     */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    /**
     * @param  array<string, mixed>  $schema
     *
     * @throws OpenAIResponsesApiException
     */
    public function call(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        string $schemaName,
        int $maxOutputTokens,
        string $logPrefix,
    ): OpenAIResponsesApiResult {
        if ($apiKey === '') {
            throw new OpenAIResponsesApiException('No OpenAI API key is configured (OPENAI_API_KEY).');
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $exception) => $this->isRetryable($exception),
                    throw: false,
                )
                ->post(self::ENDPOINT, [
                    'model' => $model,
                    'input' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_output_tokens' => $maxOutputTokens,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => $schemaName,
                            'schema' => $schema,
                            'strict' => true,
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning("{$logPrefix}: OpenAI connection failure.", ['model' => $model]);

            throw new OpenAIResponsesApiException('OpenAI request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning("{$logPrefix}: OpenAI request failed.", ['model' => $model, 'status' => $response->status()]);

            throw new OpenAIResponsesApiException("OpenAI request failed with HTTP status {$response->status()}.");
        }

        $body = $response->json();
        $resolvedModel = is_array($body) && is_string($body['model'] ?? null) ? $body['model'] : $model;

        $this->assertCompleted($body, $model, $logPrefix);

        $decoded = $this->decodeStructuredContent($this->extractOutputText($body, $logPrefix));

        $usage = is_array($body) && is_array($body['usage'] ?? null) ? $body['usage'] : null;
        if ($usage !== null) {
            Log::info("{$logPrefix}: OpenAI usage.", [
                'model' => $resolvedModel,
                'input_tokens' => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
            ]);
        }

        return new OpenAIResponsesApiResult(model: $resolvedModel, structuredContent: $decoded);
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && in_array($exception->response->status(), self::RETRYABLE_STATUSES, true);
    }

    private function assertCompleted(mixed $body, string $model, string $logPrefix): void
    {
        $status = is_array($body) ? ($body['status'] ?? null) : null;

        if ($status !== 'completed') {
            Log::warning("{$logPrefix}: OpenAI response did not complete.", [
                'model' => $model,
                'status' => is_string($status) ? $status : 'unknown',
            ]);

            throw new OpenAIResponsesApiException(
                'OpenAI response did not complete successfully (status: '.(is_string($status) ? $status : 'unknown').').'
            );
        }
    }

    /**
     * @param  mixed  $body  Decoded JSON body of the Responses API response.
     */
    private function extractOutputText(mixed $body, string $logPrefix): string
    {
        $output = is_array($body) ? ($body['output'] ?? null) : null;

        if (! is_array($output)) {
            throw new OpenAIResponsesApiException('OpenAI response had no output items.');
        }

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $parts = is_array($item['content'] ?? null) ? $item['content'] : [];

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (($part['type'] ?? null) === 'refusal') {
                    throw new OpenAIResponsesApiException('OpenAI refused to produce structured output for this request.');
                }

                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    return $part['text'];
                }
            }
        }

        throw new OpenAIResponsesApiException('OpenAI response contained no output_text content part.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStructuredContent(string $text): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OpenAIResponsesApiException('OpenAI response content was not valid JSON.', previous: $e);
        }

        if (! is_array($decoded)) {
            throw new OpenAIResponsesApiException('OpenAI response content did not decode to a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
