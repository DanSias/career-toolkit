<?php

use App\Contracts\GeneratesJobAnalysis;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Exceptions\JobAnalysisProviderException;
use App\Jobs\GenerateJobAnalysisJob;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Support\JobAnalysis\GenerateJobAnalysis;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use App\Support\ProviderDiagnostics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\FakeJobAnalysisProvider;
use Tests\Support\JobAnalysisFixtures;

/**
 * GenerateJobAnalysisJob::handle() called directly, synchronously —
 * exactly what running it through a real (or Queue::fake()'d, later
 * processed) queue worker would do, without needing an actual queue
 * connection in these tests. Uses the same FakeJobAnalysisProvider/
 * JobAnalysisFixtures conventions as GenerateJobAnalysisTest.php — the
 * underlying GenerateJobAnalysis service is completely unmodified and
 * reused as-is. See docs/job-analysis-generation.md "Async Job
 * Analysis".
 */
function queuedAttemptFor(JobPosting $jobPosting): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Queued,
    ]);
}

function fakeJobAnalysisProviderInstance(): FakeJobAnalysisProvider
{
    $fake = new FakeJobAnalysisProvider;
    app()->instance(GeneratesJobAnalysis::class, $fake);

    return $fake;
}

it('transitions queued -> running -> succeeded and persists the result', function () {
    $jobPosting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = queuedAttemptFor($jobPosting);
    fakeJobAnalysisProviderInstance()->willReturn(new JobAnalysisProviderResponse(
        provider: 'ollama',
        model: 'qwen3.8:27b',
        structuredContent: JobAnalysisFixtures::validPayload(),
    ));

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->started_at)->not->toBeNull()
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->result_id)->not->toBeNull()
        ->and($fresh->provider)->toBe('ollama')
        ->and($fresh->model)->toBe('qwen3.8:27b');
});

it('stores the actual generated JobAnalysis id as result_id', function () {
    $jobPosting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = queuedAttemptFor($jobPosting);
    fakeJobAnalysisProviderInstance()->willReturn(new JobAnalysisProviderResponse(
        provider: 'ollama',
        model: 'qwen3.8:27b',
        structuredContent: JobAnalysisFixtures::validPayload(),
    ));

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    $analysis = $jobPosting->jobAnalyses()->first();
    expect($analysis)->not->toBeNull()
        ->and($attempt->fresh()->result_id)->toBe($analysis->id);
});

it('transitions running -> failed on a provider/transport failure, preserving safe diagnostics', function () {
    $jobPosting = JobPosting::factory()->create();
    $attempt = queuedAttemptFor($jobPosting);
    fakeJobAnalysisProviderInstance()->willFail(new JobAnalysisProviderException(
        'Ollama response was truncated before completing (finish_reason: length) — the configured max output token budget was not enough for this response.',
        diagnostics: new ProviderDiagnostics(
            model: 'qwen3.8:27b',
            finishReason: 'length',
            usage: ['prompt_tokens' => 5162, 'completion_tokens' => 12639, 'total_tokens' => 17801],
            maxOutputTokens: 16000,
            timeoutSeconds: 300,
        ),
    ));

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->failure_category)->toBe('provider_error')
        ->and($fresh->failure_message)->toContain('truncated')
        ->and($fresh->model)->toBe('qwen3.8:27b')
        ->and($fresh->finish_reason)->toBe('length')
        ->and($fresh->prompt_tokens)->toBe(5162)
        ->and($fresh->completion_tokens)->toBe(12639)
        ->and($fresh->total_tokens)->toBe(17801)
        ->and($fresh->result_id)->toBeNull();
});

it('transitions running -> failed on a schema/enum validation failure', function () {
    $jobPosting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = queuedAttemptFor($jobPosting);
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['category'] = 'not_a_real_category';
    fakeJobAnalysisProviderInstance()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('validation_error')
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->result_id)->toBeNull();
});

it('transitions running -> failed when evidence_refs cites a nonexistent segment id, without persisting anything', function () {
    $jobPosting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = queuedAttemptFor($jobPosting);
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = ['S999'];
    fakeJobAnalysisProviderInstance()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('validation_error')
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->result_id)->toBeNull();
});

/**
 * Development-stage diagnostic added after a real TRM Labs run failed
 * at findings.25.evidence.0 with no durable record of the offending
 * excerpt (see docs/job-analysis-generation.md "Async Job Analysis")
 * — back when EvidenceExcerptVerifier's $context carried a
 * finding_index/evidence_index/excerpt for exactly this failure shape.
 * Under V4, EvidenceExcerptVerifier is gone: every
 * InvalidJobAnalysisResponseException now comes from
 * JobAnalysisResponseValidator, whose own throw never populates
 * $context. logEvidenceVerificationDiagnostic()'s trigger condition
 * ($e->context !== null) can therefore never be true anymore — this is
 * confirmed below rather than silently assumed, since the method
 * itself is deliberately left in place (harmless, and structurally
 * correct if a future context-carrying exception shape is ever
 * reintroduced) rather than deleted along with EvidenceExcerptVerifier.
 */
it('never logs the now-unreachable evidence-verification diagnostic line for an invalid evidence_refs id', function () {
    $jobPosting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = queuedAttemptFor($jobPosting);
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = ['S999'];
    fakeJobAnalysisProviderInstance()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));
    Log::spy();

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    // Exactly one warning fires — the pre-existing generic line — never
    // the evidence-only diagnostic, which has no trigger condition left
    // under V4.
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => $message === 'JobAnalysis generation failed.');
});

it('does not leave the attempt stuck in running when an unexpected exception escapes handle()', function () {
    $jobPosting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = queuedAttemptFor($jobPosting);

    // A provider double that throws something GenerateJobAnalysisJob's
    // handle() deliberately does NOT catch — simulating a genuinely
    // unexpected failure (a bug, not an ordinary generation failure).
    app()->instance(GeneratesJobAnalysis::class, new class implements GeneratesJobAnalysis
    {
        public function generate(string $systemPrompt, string $userPrompt, array $schema): JobAnalysisProviderResponse
        {
            throw new RuntimeException('Something genuinely unexpected.');
        }
    });

    $job = new GenerateJobAnalysisJob($attempt);

    expect(fn () => $job->handle(app(GenerateJobAnalysis::class)))
        ->toThrow(RuntimeException::class, 'Something genuinely unexpected.');

    // handle() itself left the attempt in `running` (it never caught
    // this exception) — failed() is what Laravel's queue worker calls
    // next, and is what actually guarantees it can't stay stuck there.
    expect($attempt->fresh()->status)->toBe(GenerationStatus::Running);

    $job->failed(new RuntimeException('Something genuinely unexpected.'));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('unexpected_error')
        ->and($fresh->finished_at)->not->toBeNull();
});

it('logs failures the same way the prior synchronous controller path did', function () {
    $jobPosting = JobPosting::factory()->create();
    $attempt = queuedAttemptFor($jobPosting);
    fakeJobAnalysisProviderInstance()->willFail(new JobAnalysisProviderException('Ollama request failed: connection error.'));
    Log::spy();

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'JobAnalysis generation failed.'
            && $context['job_posting_id'] === $jobPosting->id
            && $context['generation_attempt_id'] === $attempt->id
        );
});

it('never persists raw prompt/response/job-description/evidence content on the GenerationAttempt row', function () {
    $description = 'A very distinctive, never-to-be-persisted-on-the-attempt job description sentence.';
    $jobPosting = JobPosting::factory()->create(['description' => $description]);
    $attempt = queuedAttemptFor($jobPosting);
    $payload = JobAnalysisFixtures::validPayload();
    fakeJobAnalysisProviderInstance()->willReturn(new JobAnalysisProviderResponse('ollama', 'qwen3.8:27b', $payload));

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    $raw = DB::table('generation_attempts')->find($attempt->id);
    $serialized = json_encode($raw);

    expect($serialized)->not->toContain($description)
        ->and($serialized)->not->toContain('Minimum 5 years of backend engineering experience required.')
        ->and(array_keys((array) $raw))->not->toContain('raw_response')
        ->and(array_keys((array) $raw))->not->toContain('input_snapshot')
        ->and(array_keys((array) $raw))->not->toContain('prompt');
});
