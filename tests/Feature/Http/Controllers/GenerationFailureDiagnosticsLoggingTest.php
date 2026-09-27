<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeGenerationProviderException;
use App\Models\JobPosting;
use App\Support\ProviderDiagnostics;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;
use Tests\Support\ResumeVariantFixtures;

/**
 * Proves the diagnostics improvement identified after the real TRM Labs
 * truncation failure: App\Support\ProviderDiagnostics (model,
 * finish_reason, token usage, configured max output tokens/timeout) —
 * already preserved through the exception boundary by each Ollama
 * client (see the four OllamaXClientTest.php files) — actually reaches
 * a failure log, for ResumeVariant generation (still synchronous).
 * Never asserts on raw provider/prompt/job/resume content: only the
 * same non-content metadata already logged on a successful call.
 *
 * JobAnalysis's and JobMatch's own equivalent coverage moved to
 * tests/Feature/JobAnalysis/GenerateJobAnalysisJobTest.php and
 * tests/Feature/JobMatch/GenerateJobMatchJobTest.php respectively —
 * neither controller calls its generation pipeline synchronously
 * anymore (see docs/job-analysis-generation.md "Async Job Analysis"
 * and docs/job-match-generation.md "Async Job Match"), so there is
 * nothing left for this file to prove about either route specifically.
 */
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
