<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * The shared HTTP-transport mechanics for calling an Ollama server's
 * OpenAI-compatible Chat Completions endpoint with structured output —
 * the call itself, bounded retry on transient failures, and parsing
 * the response envelope down to a decoded structured-content array.
 *
 * Deliberately a SEPARATE class from OpenAIResponsesApiClient, not a
 * shared base or a config-switched variant of it, even though both
 * exist for the same purpose (one structured-output call, decoded and
 * handed back). The two speak genuinely different protocols: OpenAI's
 * Responses API (`input`, `max_output_tokens`, `text.format`,
 * `output[]`/`output_text`) versus OpenAI-compatible Chat Completions
 * (`messages`, `max_tokens`, `response_format`, `choices[].message.
 * content`) — Ollama's OpenAI-compatible layer documents Chat
 * Completions as its more mature/better-supported surface for
 * `response_format`, not the Responses API. Forcing one shape to cover
 * both would mean either bending Ollama's request into OpenAI's
 * Responses-API envelope (unverified whether Ollama honors structured
 * output there at all) or adding provider-conditional branches inside
 * one class. This class exists to get a second, real, working
 * implementation on record before any shared abstraction is drawn —
 * see the Career Toolkit local-Ollama-provider investigation for the
 * full reasoning. Do not merge this with OpenAIResponsesApiClient
 * without a concrete second data point (e.g. Job Match, Resume
 * Selection/Wording also needing this) showing what's actually common.
 */
final class OllamaChatCompletionsClient
{
    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    /**
     * Same reasoning as OpenAIResponsesApiClient::RETRYABLE_STATUSES:
     * transient/worth retrying only. An ordinary 4xx (bad request,
     * unknown model) is a request/configuration problem a retry can't
     * fix.
     */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    /**
     * @param  array<string, mixed>  $schema
     * @param  ?string  $reasoningEffort  When null (the default), the request
     *                                    body is byte-for-byte identical to before this parameter existed —
     *                                    no reasoning-related field is sent at all, and the model's own
     *                                    default thinking behavior applies. When non-null, adds exactly one
     *                                    top-level `reasoning_effort` field with this value — the single
     *                                    OpenAI-compatible reasoning control this transport speaks, per the
     *                                    local-Ollama-provider investigation's Question 1. Deliberately not
     *                                    `think`/`reasoning` as well: one unambiguous variable per call, not
     *                                    several speculative ones sent together.
     *
     * @throws OllamaChatCompletionsException
     */
    public function call(
        string $baseUrl,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        string $schemaName,
        int $maxOutputTokens,
        int $timeoutSeconds,
        string $logPrefix,
        ?string $reasoningEffort = null,
    ): OllamaChatCompletionsResult {
        if ($baseUrl === '') {
            throw new OllamaChatCompletionsException('No Ollama base URL is configured (OLLAMA_BASE_URL).');
        }

        $requestBody = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => $maxOutputTokens,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'schema' => $schema,
                    'strict' => true,
                ],
            ],
        ];

        if ($reasoningEffort !== null) {
            $requestBody['reasoning_effort'] = $reasoningEffort;
        }

        try {
            $response = Http::timeout($timeoutSeconds)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $exception) => $this->isRetryable($exception),
                    throw: false,
                )
                ->post($this->endpoint($baseUrl), $requestBody);
        } catch (ConnectionException $e) {
            Log::warning("{$logPrefix}: Ollama connection failure.", ['model' => $model]);

            throw new OllamaChatCompletionsException('Ollama request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning("{$logPrefix}: Ollama request failed.", ['model' => $model, 'status' => $response->status()]);

            throw new OllamaChatCompletionsException("Ollama request failed with HTTP status {$response->status()}.");
        }

        $body = $response->json();
        $resolvedModel = is_array($body) && is_string($body['model'] ?? null) ? $body['model'] : $model;

        // Extracted here — before content extraction/decoding, which is
        // where a finish_reason:length (or malformed-content) failure
        // throws — so that failure can carry this diagnostic metadata
        // rather than losing it. Purely observability: none of this is
        // consulted by any control-flow decision below.
        $firstChoice = is_array($body) && is_array($body['choices'][0] ?? null) ? $body['choices'][0] : null;
        $finishReasonForDiagnostics = is_string($firstChoice['finish_reason'] ?? null) ? $firstChoice['finish_reason'] : null;
        $usageForDiagnostics = is_array($body) && is_array($body['usage'] ?? null) ? $body['usage'] : null;

        try {
            $decoded = $this->decodeStructuredContent($this->extractMessageContent($body, $logPrefix));
        } catch (OllamaChatCompletionsException $e) {
            throw new OllamaChatCompletionsException(
                $e->getMessage(),
                previous: $e,
                model: $resolvedModel,
                finishReason: $finishReasonForDiagnostics,
                usage: $usageForDiagnostics,
            );
        }

        if ($usageForDiagnostics !== null) {
            Log::info("{$logPrefix}: Ollama usage.", [
                'model' => $resolvedModel,
                'prompt_tokens' => $usageForDiagnostics['prompt_tokens'] ?? null,
                'completion_tokens' => $usageForDiagnostics['completion_tokens'] ?? null,
                'total_tokens' => $usageForDiagnostics['total_tokens'] ?? null,
            ]);
        }

        return new OllamaChatCompletionsResult(
            model: $resolvedModel,
            structuredContent: $decoded,
            finishReason: $finishReasonForDiagnostics,
            usage: $usageForDiagnostics,
        );
    }

    /**
     * Strips any trailing slash(es) so a configured base URL of either
     * `http://host:11434` or `http://host:11434/` produces the same,
     * correctly-formed endpoint — the one normalization the base URL
     * needs so configuration can later point somewhere other than the
     * current LAN host without a code change.
     */
    private function endpoint(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/v1/chat/completions';
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && in_array($exception->response->status(), self::RETRYABLE_STATUSES, true);
    }

    /**
     * @param  mixed  $body  Decoded JSON body of the Chat Completions response.
     */
    private function extractMessageContent(mixed $body, string $logPrefix): string
    {
        $choices = is_array($body) ? ($body['choices'] ?? null) : null;

        if (! is_array($choices) || $choices === []) {
            throw new OllamaChatCompletionsException('Ollama response had no choices.');
        }

        $firstChoice = $choices[0];

        if (! is_array($firstChoice)) {
            throw new OllamaChatCompletionsException('Ollama response choices[0] was not an object.');
        }

        $finishReason = $firstChoice['finish_reason'] ?? null;

        if ($finishReason === 'length') {
            Log::warning("{$logPrefix}: Ollama response was truncated.", ['finish_reason' => $finishReason]);

            throw new OllamaChatCompletionsException(
                'Ollama response was truncated before completing (finish_reason: length) — the configured '.
                'max output token budget was not enough for this response.'
            );
        }

        if ($finishReason === 'content_filter') {
            throw new OllamaChatCompletionsException('Ollama declined to complete this response (finish_reason: content_filter).');
        }

        $message = is_array($firstChoice['message'] ?? null) ? $firstChoice['message'] : null;
        $content = $message['content'] ?? null;

        if (! is_string($content) || $content === '') {
            throw new OllamaChatCompletionsException('Ollama response contained no message content.');
        }

        return $content;
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
            throw new OllamaChatCompletionsException('Ollama response content was not valid JSON.', previous: $e);
        }

        if (! is_array($decoded)) {
            throw new OllamaChatCompletionsException('Ollama response content did not decode to a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
