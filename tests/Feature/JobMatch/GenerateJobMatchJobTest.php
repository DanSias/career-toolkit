<?php

use App\Contracts\GeneratesJobMatch;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Exceptions\JobMatchProviderException;
use App\Jobs\GenerateJobMatchJob;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Support\JobMatch\GenerateJobMatch;
use App\Support\JobMatch\JobMatchProviderResponse;
use App\Support\ProviderDiagnostics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\FakeJobMatchProvider;
use Tests\Support\JobMatchFixtures;

/**
 * GenerateJobMatchJob::handle() called directly, synchronously — the
 * same convention tests/Feature/JobAnalysis/GenerateJobAnalysisJobTest.php
 * uses for GenerateJobAnalysisJob. The underlying GenerateJobMatch
 * service is completely unmodified and reused as-is. See
 * docs/job-match-generation.md "Async Job Match".
 */
function queuedMatchAttemptFor(JobAnalysis $analysis): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Queued,
    ]);
}

function fakeJobMatchProviderInstance(): FakeJobMatchProvider
{
    $fake = new FakeJobMatchProvider;
    app()->instance(GeneratesJobMatch::class, $fake);

    return $fake;
}

it('transitions queued -> running -> succeeded and persists the result', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    $attempt = queuedMatchAttemptFor($analysis);
    fakeJobMatchProviderInstance()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    (new GenerateJobMatchJob($attempt))->handle(app(GenerateJobMatch::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->started_at)->not->toBeNull()
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->result_id)->not->toBeNull()
        ->and($fresh->provider)->toBe('openai')
        ->and($fresh->model)->toBe('gpt-5.6-test');

    $match = JobMatch::find($fresh->result_id);
    expect($match)->not->toBeNull()
        ->and($match->career_profile_id)->toBe($profile->id);
});

it('stores the actual generated JobMatch id as result_id, and correct prompt/schema versions', function () {
    ['factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    $attempt = queuedMatchAttemptFor($analysis);
    fakeJobMatchProviderInstance()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    (new GenerateJobMatchJob($attempt))->handle(app(GenerateJobMatch::class));

    $match = $analysis->jobMatches()->sole();
    $fresh = $attempt->fresh();
    expect($fresh->result_id)->toBe($match->id)
        ->and($fresh->prompt_version)->toBe($match->prompt_version)
        ->and($fresh->schema_version)->toBe($match->schema_version);
});

it('transitions running -> failed on a provider/transport failure, preserving safe diagnostics', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();
    JobMatchFixtures::candidate();
    $attempt = queuedMatchAttemptFor($analysis);
    fakeJobMatchProviderInstance()->willFail(new JobMatchProviderException(
        'Ollama response was truncated before completing (finish_reason: length).',
        diagnostics: new ProviderDiagnostics(
            model: 'qwen3.8:27b',
            finishReason: 'length',
            usage: ['prompt_tokens' => 4000, 'completion_tokens' => 9000, 'total_tokens' => 13000],
            maxOutputTokens: 8192,
            timeoutSeconds: 600,
        ),
    ));

    (new GenerateJobMatchJob($attempt))->handle(app(GenerateJobMatch::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->failure_category)->toBe('provider_error')
        ->and($fresh->failure_message)->toContain('truncated')
        ->and($fresh->model)->toBe('qwen3.8:27b')
        ->and($fresh->finish_reason)->toBe('length')
        ->and($fresh->prompt_tokens)->toBe(4000)
        ->and($fresh->completion_tokens)->toBe(9000)
        ->and($fresh->total_tokens)->toBe(13000)
        ->and($fresh->result_id)->toBeNull();
    expect(JobMatch::count())->toBe(0);
});

it('logs full safe provider diagnostics (no raw content) on a provider/transport failure', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();
    JobMatchFixtures::candidate();
    $attempt = queuedMatchAttemptFor($analysis);
    fakeJobMatchProviderInstance()->willFail(new JobMatchProviderException(
        'Ollama response was truncated before completing (finish_reason: length) — the configured max output token budget was not enough for this response.',
        diagnostics: new ProviderDiagnostics(
            model: 'qwen3.8:27b',
            finishReason: 'length',
            usage: ['prompt_tokens' => 35030, 'completion_tokens' => 16000, 'total_tokens' => 51030],
            maxOutputTokens: 16000,
            timeoutSeconds: 600,
        ),
    ));
    Log::spy();

    (new GenerateJobMatchJob($attempt))->handle(app(GenerateJobMatch::class));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($analysis) {
            return $message === 'JobMatch generation failed.'
                && $context['job_analysis_id'] === $analysis->id
                && $context['exception'] === JobMatchProviderException::class
                && $context['finish_reason'] === 'length'
                && $context['completion_tokens'] === 16000
                && $context['max_output_tokens_configured'] === 16000
                && $context['provider_timeout_seconds'] === 600;
        });
});

it('transitions running -> failed on a deterministic validation failure, persisting nothing', function () {
    ['factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    $attempt = queuedMatchAttemptFor($analysis);
    $badResponse = JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education);
    $badResponse['findings'][0]['coverage'] = 'definitely_hired'; // not a real enum value
    fakeJobMatchProviderInstance()->willReturn(new JobMatchProviderResponse('openai', 'gpt-5.6-test', $badResponse));

    (new GenerateJobMatchJob($attempt))->handle(app(GenerateJobMatch::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('validation_error')
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->result_id)->toBeNull();
    expect(JobMatch::count())->toBe(0);
});

it('does not leave the attempt stuck in running when an unexpected exception escapes handle()', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();
    JobMatchFixtures::candidate();
    $attempt = queuedMatchAttemptFor($analysis);

    app()->instance(GeneratesJobMatch::class, new class implements GeneratesJobMatch
    {
        public function generate(string $systemPrompt, string $userPrompt, array $schema): JobMatchProviderResponse
        {
            throw new RuntimeException('Something genuinely unexpected.');
        }
    });

    $job = new GenerateJobMatchJob($attempt);

    expect(fn () => $job->handle(app(GenerateJobMatch::class)))
        ->toThrow(RuntimeException::class, 'Something genuinely unexpected.');

    expect($attempt->fresh()->status)->toBe(GenerationStatus::Running);

    $job->failed(new RuntimeException('Something genuinely unexpected.'));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('unexpected_error')
        ->and($fresh->finished_at)->not->toBeNull();
});

it('logs failures the same way the prior synchronous controller path did', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();
    JobMatchFixtures::candidate();
    $attempt = queuedMatchAttemptFor($analysis);
    fakeJobMatchProviderInstance()->willFail(new JobMatchProviderException('Simulated provider failure.'));
    Log::spy();

    (new GenerateJobMatchJob($attempt))->handle(app(GenerateJobMatch::class));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'JobMatch generation failed.'
            && $context['job_analysis_id'] === $analysis->id
            && $context['generation_attempt_id'] === $attempt->id
        );
});

it('never persists raw prompt/response/candidate content on the GenerationAttempt row', function () {
    ['factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    $attempt = queuedMatchAttemptFor($analysis);
    fakeJobMatchProviderInstance()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    (new GenerateJobMatchJob($attempt))->handle(app(GenerateJobMatch::class));

    $raw = DB::table('generation_attempts')->find($attempt->id);

    expect(array_keys((array) $raw))->not->toContain('raw_response')
        ->and(array_keys((array) $raw))->not->toContain('input_snapshot')
        ->and(array_keys((array) $raw))->not->toContain('prompt');
});
