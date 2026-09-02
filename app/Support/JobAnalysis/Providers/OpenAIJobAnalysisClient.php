<?php

namespace App\Support\JobAnalysis\Providers;

use App\Contracts\GeneratesJobAnalysis;
use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Calls OpenAI's Responses API directly via Laravel's HTTP client — no
 * provider SDK. Uses OpenAI's native structured-output mechanism
 * (`text.format` with `type: "json_schema"` and `strict: true`) so the
 * provider itself constrains generation to the supplied schema, rather
 * than a JSON-mode/forced-function-call workaround. Deliberately sends
 * no temperature/top_p — none is required for schema-constrained
 * generation and the current model has no documented need for one.
 *
 * This class knows nothing about JobPosting, Eloquent, or any
 * candidate-side model — it only ever sees the plain prompt strings and
 * schema array the orchestrator hands it. See
 * app/Contracts/GeneratesJobAnalysis.php and
 * docs/job-analysis-generation.md.
 */
final class OpenAIJobAnalysisClient implements GeneratesJobAnalysis
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /**
     * Generous enough for a multi-finding analysis with several
     * evidence rows each; not user-configurable — this is an
     * implementation detail of the provider call, not a product knob.
     */
    private const MAX_OUTPUT_TOKENS = 8192;

    private const TIMEOUT_SECONDS = 120;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    /**
     * Only these are treated as transient/worth retrying: request
     * timeout, rate limiting, and server-side failures. An ordinary 4xx
     * (bad request, bad/missing API key, not found) is a
     * request/configuration problem that a retry cannot fix.
     */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobAnalysisProviderResponse
    {
        if ($this->apiKey === '') {
            throw new JobAnalysisProviderException('No OpenAI API key is configured (OPENAI_API_KEY).');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $exception) => $this->isRetryable($exception),
                    throw: false,
                )
                ->post(self::ENDPOINT, [
                    'model' => $this->model,
                    'input' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'job_analysis',
                            'schema' => $schema,
                            'strict' => true,
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('JobAnalysis generation: OpenAI connection failure.', [
                'model' => $this->model,
            ]);

            throw new JobAnalysisProviderException('OpenAI request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('JobAnalysis generation: OpenAI request failed.', [
                'model' => $this->model,
                'status' => $response->status(),
            ]);

            throw new JobAnalysisProviderException(
                "OpenAI request failed with HTTP status {$response->status()}."
            );
        }

        $body = $response->json();
        $model = is_array($body) && is_string($body['model'] ?? null) ? $body['model'] : $this->model;

        $this->assertCompleted($body);

        $decoded = $this->decodeStructuredContent($this->extractOutputText($body));

        // Token usage carries no credentials/candidate data — safe to
        // log for cost observability. Not persisted anywhere.
        $usage = is_array($body) && is_array($body['usage'] ?? null) ? $body['usage'] : null;
        if ($usage !== null) {
            Log::info('JobAnalysis generation: OpenAI usage.', [
                'model' => $model,
                'input_tokens' => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
            ]);
        }

        return new JobAnalysisProviderResponse(
            provider: 'openai',
            model: $model,
            structuredContent: $decoded,
        );
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
     * A Responses API call can return HTTP 200 while still representing
     * a failure at the response level (e.g. `status: "failed"` or
     * `"incomplete"`, often alongside a top-level `error` object) — that
     * failure has to be checked explicitly rather than inferred from
     * the HTTP status code alone.
     */
    private function assertCompleted(mixed $body): void
    {
        $status = is_array($body) ? ($body['status'] ?? null) : null;

        if ($status !== 'completed') {
            Log::warning('JobAnalysis generation: OpenAI response did not complete.', [
                'model' => $this->model,
                'status' => is_string($status) ? $status : 'unknown',
            ]);

            throw new JobAnalysisProviderException(
                'OpenAI response did not complete successfully (status: '.(is_string($status) ? $status : 'unknown').').'
            );
        }
    }

    /**
     * @param  mixed  $body  Decoded JSON body of the Responses API response.
     */
    private function extractOutputText(mixed $body): string
    {
        $output = is_array($body) ? ($body['output'] ?? null) : null;

        if (! is_array($output)) {
            throw new JobAnalysisProviderException('OpenAI response had no output items.');
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
                    throw new JobAnalysisProviderException(
                        'OpenAI refused to produce structured output for this job posting.'
                    );
                }

                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    return $part['text'];
                }
            }
        }

        throw new JobAnalysisProviderException('OpenAI response contained no output_text content part.');
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
            throw new JobAnalysisProviderException('OpenAI response content was not valid JSON.', previous: $e);
        }

        if (! is_array($decoded)) {
            throw new JobAnalysisProviderException('OpenAI response content did not decode to a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
