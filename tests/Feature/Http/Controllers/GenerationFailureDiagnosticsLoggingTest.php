<?php

use App\Contracts\GeneratesJobMatch;
use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Exceptions\JobMatchProviderException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\ProviderDiagnostics;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeJobMatchProvider;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;
use Tests\Support\ResumeVariantFixtures;

/**
 * Proves the diagnostics improvement identified after the real TRM Labs
 * truncation failure: App\Support\ProviderDiagnostics (model,
 * finish_reason, token usage, configured max output tokens/timeout) —
 * already preserved through the exception boundary by each Ollama
 * client (see the four OllamaXClientTest.php files) — actually reaches
 * a failure log, for JobMatch and ResumeVariant generation (still
 * synchronous). Never asserts on raw provider/prompt/job/resume
 * content: only the same non-content metadata already logged on a
 * successful call.
 *
 * JobAnalysis's own equivalent coverage moved to
 * tests/Feature/JobAnalysis/GenerateJobAnalysisJobTest.php —
 * JobAnalysisController::store() no longer calls the generation
 * pipeline synchronously at all (see docs/job-analysis-generation.md
 * "Async Job Analysis"), so there is nothing left for this file to
 * prove about that route specifically.
 */
it('logs provider diagnostics on a JobMatch generation failure, with no raw content', function () {
    $jobPosting = JobPosting::factory()->create();
    $jobAnalysis = JobAnalysis::factory()->create(['job_posting_id' => $jobPosting->id]);
    app()->instance(GeneratesJobMatch::class, (new FakeJobMatchProvider)->willFail(
        new JobMatchProviderException(
            'Ollama response was truncated before completing (finish_reason: length) — the configured max output token budget was not enough for this response.',
            diagnostics: new ProviderDiagnostics(
                model: 'qwen3.8:27b',
                finishReason: 'length',
                usage: ['prompt_tokens' => 35030, 'completion_tokens' => 16000, 'total_tokens' => 51030],
                maxOutputTokens: 16000,
                timeoutSeconds: 600,
            ),
        )
    ));
    Log::spy();

    $response = $this->post(route('jobs.analyses.matches.store', [$jobPosting, $jobAnalysis]));

    $response->assertRedirect()->assertSessionHasErrors('match_generation');
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($jobAnalysis) {
            return $message === 'JobMatch generation failed.'
                && $context['job_analysis_id'] === $jobAnalysis->id
                && $context['exception'] === JobMatchProviderException::class
                && $context['finish_reason'] === 'length'
                && $context['completion_tokens'] === 16000
                && $context['max_output_tokens_configured'] === 16000
                && $context['provider_timeout_seconds'] === 600;
        });
});

it('logs provider diagnostics on a ResumeVariant generation failure, with no raw content', function () {
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $jobMatch = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );
    $jobPosting = JobPosting::find($job['analysis']->job_posting_id);
    $jobAnalysis = $job['analysis'];

    app()->instance(GeneratesResumeSelection::class, (new FakeResumeSelectionProvider)->willFail(
        new ResumeGenerationProviderException(
            'Ollama response was truncated before completing (finish_reason: length) — the configured max output token budget was not enough for this response.',
            diagnostics: new ProviderDiagnostics(
                model: 'qwen3.8:27b',
                finishReason: 'length',
                usage: ['prompt_tokens' => 35030, 'completion_tokens' => 16000, 'total_tokens' => 51030],
                maxOutputTokens: 16000,
                timeoutSeconds: 900,
            ),
        )
    ));
    app()->instance(GeneratesResumeWording::class, new FakeResumeWordingProvider);
    Log::spy();

    $response = $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $jobAnalysis, $jobMatch]));

    $response->assertRedirect()->assertSessionHasErrors('resume_generation');
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($jobMatch) {
            return $message === 'ResumeVariant generation failed.'
                && $context['job_match_id'] === $jobMatch->id
                && $context['exception'] === ResumeGenerationProviderException::class
                && $context['finish_reason'] === 'length'
                && $context['completion_tokens'] === 16000
                && $context['max_output_tokens_configured'] === 16000
                && $context['provider_timeout_seconds'] === 900;
        });
});
