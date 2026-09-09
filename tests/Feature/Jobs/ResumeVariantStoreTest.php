<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Models\ResumeVariant;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;
use Tests\Support\ResumeVariantFixtures;

/**
 * @return array<string, mixed>
 */
function resumeStoreValidSelection(array $candidate, array $job): array
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
        'target_term_usages' => [],
    ];
}

/**
 * @return array<string, mixed>
 */
function resumeStoreValidWording(array $candidate): array
{
    return [
        'summary' => 'Senior engineer.',
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'bullets' => [['bullet_group_index' => 0, 'text' => 'Independently implemented the technical solution from team-provided requirements.']],
        ]],
    ];
}

function bindResumeFakes(): array
{
    $selection = new FakeResumeSelectionProvider;
    $wording = new FakeResumeWordingProvider;
    app()->instance(GeneratesResumeSelection::class, $selection);
    app()->instance(GeneratesResumeWording::class, $wording);

    return [$selection, $wording];
}

function resumeStoreFixtures(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    return [$candidate, $job, $match];
}

it('generates a new immutable ResumeVariant and redirects to its detail page', function () {
    [$candidate, $job, $match] = resumeStoreFixtures();
    $jobPosting = $match->jobAnalysis->jobPosting;
    [$selection, $wording] = bindResumeFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', resumeStoreValidSelection($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', resumeStoreValidWording($candidate)));

    $response = $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    $variant = ResumeVariant::sole();
    $response->assertRedirect(route('jobs.analyses.matches.resume.show', [$jobPosting, $match->jobAnalysis, $match, $variant]));
});

it('allows generating a new resume even when one already exists for the same match', function () {
    [$candidate, $job, $match] = resumeStoreFixtures();
    $jobPosting = $match->jobAnalysis->jobPosting;
    [$selection, $wording] = bindResumeFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', resumeStoreValidSelection($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', resumeStoreValidWording($candidate)));

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));
    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    expect(ResumeVariant::where('job_match_id', $match->id)->count())->toBe(2);
});

it('redirects back with a safe error message and persists nothing on generation failure', function () {
    [$candidate, $job, $match] = resumeStoreFixtures();
    $jobPosting = $match->jobAnalysis->jobPosting;

    $invalidSelection = resumeStoreValidSelection($candidate, $job);
    $invalidSelection['experience'][0]['title_choice'] = 'segment_5'; // illegal for this role (only full/segment_1/segment_2 exist) — triggers real validator rejection

    [$selection, $wording] = bindResumeFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $invalidSelection));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', resumeStoreValidWording($candidate)));

    $matchShowUrl = route('jobs.analyses.matches.show', [$jobPosting, $match->jobAnalysis, $match]);
    $response = $this->from($matchShowUrl)->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    $response->assertRedirect($matchShowUrl);
    $response->assertSessionHasErrors('resume_generation');

    $message = session('errors')->get('resume_generation')[0];
    expect($message)->not->toContain('title_choice')
        ->and($message)->not->toContain('Illuminate\\')
        ->and($message)->not->toContain('App\\Exceptions');
    expect(ResumeVariant::count())->toBe(0);
});

it('returns 404 when the JobMatch does not belong to the given JobAnalysis', function () {
    [$candidate, $job, $match] = resumeStoreFixtures();
    $jobPosting = $match->jobAnalysis->jobPosting;
    ['analysis' => $unrelatedAnalysis] = ResumeVariantFixtures::job();

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $unrelatedAnalysis, $match]))
        ->assertNotFound();
});

it('returns 404 for a nonexistent JobMatch', function () {
    [$candidate, $job, $match] = resumeStoreFixtures();
    $jobPosting = $match->jobAnalysis->jobPosting;

    $this->post("/jobs/{$jobPosting->id}/analyses/{$match->jobAnalysis->id}/matches/999999/resume")
        ->assertNotFound();
});
