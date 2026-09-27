<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Exceptions\ResumeGenerationProviderException;
use App\Jobs\GenerateResumeVariantJob;
use App\Models\CareerProfile;
use App\Models\GenerationAttempt;
use App\Models\JobMatch;
use App\Models\ResumeVariant;
use App\Support\ProviderDiagnostics;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;
use Tests\Support\ResumeVariantFixtures;

/**
 * GenerateResumeVariantJob::handle() called directly, synchronously —
 * the same convention the Job Analysis/Job Match equivalents use. The
 * underlying GenerateResumeVariant service is completely unmodified and
 * reused as-is. See docs/resume-variant-generation.md "Async Resume".
 */
function queuedResumeAttemptFor(JobMatch $match): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => (new JobMatch)->getMorphClass(),
        'subject_id' => $match->id,
        'generation_type' => GenerationType::ResumeVariant,
        'status' => GenerationStatus::Queued,
    ]);
}

function bindResumeVariantFakes(): array
{
    $selection = new FakeResumeSelectionProvider;
    $wording = new FakeResumeWordingProvider;
    app()->instance(GeneratesResumeSelection::class, $selection);
    app()->instance(GeneratesResumeWording::class, $wording);

    return [$selection, $wording];
}

function resumeJobFixtures(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    return [$candidate, $job, $match];
}

/**
 * @return array<string, mixed>
 */
function validResumeSelectionPayload(array $candidate, array $job): array
{
    return [
        'summary_evidence' => [$candidate['factIndependent']->key],
        'skills' => [],
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'title_choice' => 'full',
            'bullet_groups' => [[
                'project_id' => -1,
                'order' => 1,
                'career_fact_keys' => [$candidate['factIndependent']->key],
                'job_analysis_finding_ids' => [$job['ownershipFinding']->id],
            ]],
        ]],
        'selected_projects' => [],
        'target_term_usages' => [],
    ];
}

/**
 * @return array<string, mixed>
 */
function validResumeWordingPayload(array $candidate): array
{
    return [
        'summary' => 'Senior engineer.',
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'bullets' => [['bullet_group_index' => 0, 'text' => 'Independently implemented the technical solution from team-provided requirements.']],
        ]],
        'selected_projects' => [],
    ];
}

it('transitions queued -> running -> succeeded, persisting result_id and only the truthful shared schema_version', function () {
    [$candidate, $job, $match] = resumeJobFixtures();
    $attempt = queuedResumeAttemptFor($match);
    [$selection, $wording] = bindResumeVariantFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validResumeSelectionPayload($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validResumeWordingPayload($candidate)));

    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    $variant = ResumeVariant::sole();
    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->started_at)->not->toBeNull()
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->result_id)->toBe($variant->id)
        ->and($fresh->schema_version)->toBe($variant->schema_version)
        // Deliberately conservative: Selection and Wording are
        // independently configurable and already tracked separately on
        // the ResumeVariant itself (selection_generated_by/
        // wording_generated_by) — the attempt's singular columns are
        // never synthesized into a misleading combined identity.
        ->and($fresh->provider)->toBeNull()
        ->and($fresh->model)->toBeNull()
        ->and($fresh->prompt_version)->toBeNull();
});

it('uses the CareerProfile frozen to the JobMatch, never whichever profile currently resolves as "current"', function () {
    // Created first, so it has the lowest id and would be
    // CurrentCareerProfile::resolve()'s answer if this job incorrectly
    // called it instead of trusting $jobMatch->careerProfile.
    $unrelatedOlderProfile = CareerProfile::factory()->create();

    [$candidate, $job, $match] = resumeJobFixtures();
    expect($match->career_profile_id)->not->toBe($unrelatedOlderProfile->id);

    $attempt = queuedResumeAttemptFor($match);
    [$selection, $wording] = bindResumeVariantFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validResumeSelectionPayload($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validResumeWordingPayload($candidate)));

    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    $variant = ResumeVariant::sole();
    expect($variant->career_profile_id)->toBe($match->career_profile_id)
        ->and($variant->career_profile_id)->not->toBe($unrelatedOlderProfile->id);
});

it('transitions running -> failed on a Selection provider failure, preserving safe diagnostics with an honestly-null stage', function () {
    [, , $match] = resumeJobFixtures();
    $attempt = queuedResumeAttemptFor($match);
    [$selection] = bindResumeVariantFakes();
    $selection->willFail(new ResumeGenerationProviderException(
        'Ollama response was truncated before completing (finish_reason: length).',
        diagnostics: new ProviderDiagnostics(
            model: 'qwen3.8:27b',
            finishReason: 'length',
            usage: ['prompt_tokens' => 4000, 'completion_tokens' => 9000, 'total_tokens' => 13000],
            maxOutputTokens: 16000,
            timeoutSeconds: 900,
        ),
    ));

    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('provider_error')
        ->and($fresh->failure_message)->toContain('truncated')
        ->and($fresh->model)->toBe('qwen3.8:27b')
        ->and($fresh->finish_reason)->toBe('length')
        ->and($fresh->prompt_tokens)->toBe(4000)
        ->and($fresh->completion_tokens)->toBe(9000)
        ->and($fresh->total_tokens)->toBe(13000)
        ->and($fresh->result_id)->toBeNull();
    expect(ResumeVariant::count())->toBe(0);
});

it('logs a null generation_stage for a provider failure, since the existing exception boundary cannot prove which stage failed', function () {
    [, , $match] = resumeJobFixtures();
    $attempt = queuedResumeAttemptFor($match);
    [$selection] = bindResumeVariantFakes();
    $selection->willFail(new ResumeGenerationProviderException('Ollama request failed: connection error.'));
    Log::spy();

    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'ResumeVariant generation failed.'
            && $context['generation_stage'] === null
        );
});

it('transitions running -> failed on a Wording provider failure after a successful Selection stage', function () {
    [$candidate, $job, $match] = resumeJobFixtures();
    $attempt = queuedResumeAttemptFor($match);
    [$selection, $wording] = bindResumeVariantFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validResumeSelectionPayload($candidate, $job)));
    $wording->willFail(new ResumeGenerationProviderException(
        'Ollama response was truncated before completing (finish_reason: length).',
        diagnostics: new ProviderDiagnostics(
            model: 'qwen3.8:27b',
            finishReason: 'length',
            usage: ['prompt_tokens' => 6000, 'completion_tokens' => 9500, 'total_tokens' => 15500],
            maxOutputTokens: 16000,
            timeoutSeconds: 900,
        ),
    ));

    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('provider_error')
        ->and($fresh->completion_tokens)->toBe(9500)
        ->and($fresh->result_id)->toBeNull();
    // Selection's own provider call succeeded, but nothing is persisted
    // until after Wording also succeeds and validates — no partial
    // ResumeVariant from the completed Selection stage alone.
    expect(ResumeVariant::count())->toBe(0);
});

it('transitions running -> failed on a Selection validation failure, logging generation_stage=selection from the existing message prefix', function () {
    [$candidate, $job, $match] = resumeJobFixtures();
    $attempt = queuedResumeAttemptFor($match);
    [$selection, $wording] = bindResumeVariantFakes();
    $invalidSelection = validResumeSelectionPayload($candidate, $job);
    $invalidSelection['experience'][0]['title_choice'] = 'segment_5'; // illegal for this role — real validator rejection
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $invalidSelection));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validResumeWordingPayload($candidate)));
    Log::spy();

    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('validation_error')
        ->and($fresh->failure_message)->toStartWith('Resume Selection provider response failed validation:')
        ->and($fresh->result_id)->toBeNull();
    expect(ResumeVariant::count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'ResumeVariant generation failed.'
            && $context['generation_stage'] === 'selection'
        );
});

it('transitions running -> failed on a Wording validation failure, logging generation_stage=wording from the existing message prefix', function () {
    [$candidate, $job, $match] = resumeJobFixtures();
    $attempt = queuedResumeAttemptFor($match);
    [$selection, $wording] = bindResumeVariantFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validResumeSelectionPayload($candidate, $job)));
    $invalidWording = validResumeWordingPayload($candidate);
    $invalidWording['experience'][0]['bullets'][0]['bullet_group_index'] = 99; // no such bullet group in the approved selection
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $invalidWording));
    Log::spy();

    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('validation_error')
        ->and($fresh->failure_message)->toStartWith('Resume Wording provider response failed validation:')
        ->and($fresh->result_id)->toBeNull();
    expect(ResumeVariant::count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'ResumeVariant generation failed.'
            && $context['generation_stage'] === 'wording'
        );
});

it('does not leave the attempt stuck in running when an unexpected exception escapes handle()', function () {
    [, , $match] = resumeJobFixtures();
    $attempt = queuedResumeAttemptFor($match);

    app()->instance(GeneratesResumeSelection::class, new class implements GeneratesResumeSelection
    {
        public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeSelectionProviderResponse
        {
            throw new RuntimeException('Something genuinely unexpected.');
        }
    });

    $job = new GenerateResumeVariantJob($attempt);

    expect(fn () => $job->handle(app(GenerateResumeVariant::class)))
        ->toThrow(RuntimeException::class, 'Something genuinely unexpected.');

    expect($attempt->fresh()->status)->toBe(GenerationStatus::Running);

    $job->failed(new RuntimeException('Something genuinely unexpected.'));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_category)->toBe('unexpected_error')
        ->and($fresh->finished_at)->not->toBeNull();
    expect(ResumeVariant::count())->toBe(0);
});

it('never persists raw prompt/response/candidate/resume content on the GenerationAttempt row', function () {
    [$candidate, $job, $match] = resumeJobFixtures();
    $attempt = queuedResumeAttemptFor($match);
    [$selection, $wording] = bindResumeVariantFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validResumeSelectionPayload($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validResumeWordingPayload($candidate)));

    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    $raw = DB::table('generation_attempts')->find($attempt->id);

    expect(array_keys((array) $raw))->not->toContain('selection_raw_response')
        ->and(array_keys((array) $raw))->not->toContain('wording_raw_response')
        ->and(array_keys((array) $raw))->not->toContain('selection_input_snapshot')
        ->and(array_keys((array) $raw))->not->toContain('wording_input_snapshot')
        ->and(array_keys((array) $raw))->not->toContain('prompt');
});
