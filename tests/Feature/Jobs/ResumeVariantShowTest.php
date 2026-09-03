<?php

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Enums\Visibility;
use App\Models\JobMatch;
use App\Models\ResumeVariant;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ResumeVariantFixtures;

function persistedResumeVariant(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    $candidate['factAws']->update(['visibility' => Visibility::Public]);

    $variant = ResumeVariant::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'job_match_id' => $match->id,
    ]);

    $bullet = $variant->experienceBullets()->create([
        'employer_id' => $candidate['employer']->id,
        'role_id' => $candidate['role']->id,
        'project_id' => $candidate['project']->id,
        'display_title' => 'Senior Software Engineer',
        'display_order' => 1,
        'text' => 'Built cloud-hosted deployment workflows on AWS, with approaches applicable to Azure.',
    ]);
    $bullet->citations()->create(['career_fact_id' => $candidate['factAws']->id]);
    $usage = $bullet->targetTermUsages()->create([
        'resume_variant_id' => $variant->id,
        'job_analysis_finding_id' => $job['azureFinding']->id,
        'target_term' => 'Azure',
        'posture' => ResumeClaimPosture::Qualified,
        'location' => ResumeTermUsageLocation::Bullet,
        'relationship_phrase_key' => ResumeQualifiedPhrase::ApplicableTo,
    ]);
    $usage->evidence()->create(['career_fact_id' => $candidate['factAws']->id]);

    $variant->summaryEvidence()->create(['career_fact_id' => $candidate['factIndependent']->id]);
    $variant->skillSelections()->create(['skill_id' => $candidate['awsSkill']->id, 'display_order' => 1]);
    $variant->educationSelections()->create(['education_id' => $candidate['education']->id, 'display_order' => 1]);

    return [$candidate, $job, $match, $variant->fresh()];
}

it('renders the resume detail page with job/match context and generation metadata', function () {
    [$candidate, $job, $match, $variant] = persistedResumeVariant();
    $jobPosting = $match->jobAnalysis->jobPosting;

    $this->get(route('jobs.analyses.matches.resume.show', [$jobPosting, $match->jobAnalysis, $match, $variant]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/resumes/show')
            ->where('job.id', $jobPosting->id)
            ->where('match.id', $match->id)
            ->where('resume.id', $variant->id)
            ->where('resume.schema_version', $variant->schema_version)
        );
});

it('includes experience grouped by role with citations and target-term usages', function () {
    [$candidate, $job, $match, $variant] = persistedResumeVariant();
    $jobPosting = $match->jobAnalysis->jobPosting;

    $this->get(route('jobs.analyses.matches.resume.show', [$jobPosting, $match->jobAnalysis, $match, $variant]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('resume.experience', 1)
            ->has('resume.experience.0.bullets', 1)
            ->where('resume.experience.0.bullets.0.citations.0.key', $candidate['factAws']->key)
            ->has('resume.experience.0.bullets.0.target_term_usages', 1)
            ->where('resume.experience.0.bullets.0.target_term_usages.0.posture', 'qualified')
            ->has('resume.skills', 1)
            ->has('resume.education', 1)
        );
});

it('404s when the ResumeVariant does not belong to the given JobMatch', function () {
    [$candidate, $job, $match, $variant] = persistedResumeVariant();
    $jobPosting = $match->jobAnalysis->jobPosting;
    ['analysis' => $unrelatedAnalysis] = ResumeVariantFixtures::job();
    $unrelatedMatch = JobMatch::factory()->create(['job_analysis_id' => $unrelatedAnalysis->id]);

    $this->get(route('jobs.analyses.matches.resume.show', [$jobPosting, $unrelatedAnalysis, $unrelatedMatch, $variant]))
        ->assertNotFound();
});

it('404s for a nonexistent ResumeVariant id', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $realMatch = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);
    $jobPosting = $analysis->jobPosting;

    $this->get("/jobs/{$jobPosting->id}/analyses/{$analysis->id}/matches/{$realMatch->id}/resume/999999")
        ->assertNotFound();
});
