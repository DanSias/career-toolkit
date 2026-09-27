<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\JobAnalysisSeniority;
use App\Models\CareerProfile;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\JobAnalysisFixtures;

function persistedAnalysis(?JobPosting $job = null, ?DateTimeInterface $generatedAt = null): JobAnalysis
{
    $job ??= JobPosting::factory()->create([
        'company' => 'Acme Corp',
        'title' => 'Senior Backend Engineer',
        'description' => JobAnalysisFixtures::DESCRIPTION,
    ]);

    $analysis = $job->jobAnalyses()->create([
        'schema_version' => '1.0',
        'prompt_version' => 'job-analysis-v1',
        'generated_by' => 'openai:gpt-5.6-test',
        'generated_at' => $generatedAt ?? now(),
        'raw_response' => JobAnalysisFixtures::validPayload(),
        'role_summary' => 'A senior backend engineering role.',
        'overall_seniority' => JobAnalysisSeniority::Senior,
        'seniority_rationale' => 'Explicitly titled Senior.',
    ]);

    // A directly-built, pre-V4 shaped persisted row — deliberately NOT
    // sourced from JobAnalysisFixtures::validPayload()'s evidence_refs
    // (that shape is a V4 provider-response payload, resolved by
    // GenerateJobAnalysis before anything is persisted; it was never a
    // database row shape). job_analysis_finding_evidence's own columns
    // (excerpt/source_section/source_locator) are unchanged by V4 — see
    // docs/job-analysis-generation.md "Async Job Analysis" — so a
    // historical row shaped exactly like this is exactly what a real
    // V1/V2/V3 analysis still looks like in the database today, and
    // must still render correctly with zero frontend changes.
    $findingsWithEvidence = [
        [
            'category' => 'required_qualification',
            'statement' => '5+ years of backend engineering experience',
            'label' => 'backend_experience_floor',
            'basis' => 'explicit',
            'requirement_strength' => 'required',
            'emphasis' => 'normal',
            'maturity' => 'unspecified',
            'years_experience_min' => 5.0,
            'years_experience_max' => null,
            'recency_requirement' => null,
            'time_horizon' => null,
            'notes' => null,
            'evidence' => [
                ['excerpt' => 'Minimum 5 years of backend engineering experience required.', 'source_section' => 'Requirements', 'source_locator' => null],
            ],
        ],
        [
            'category' => 'travel',
            'statement' => 'Travel up to 25% required for client visits',
            'label' => null,
            'basis' => 'explicit',
            'requirement_strength' => 'required',
            'emphasis' => 'high',
            'maturity' => 'unspecified',
            'years_experience_min' => null,
            'years_experience_max' => null,
            'recency_requirement' => null,
            'time_horizon' => null,
            'notes' => 'Stated twice in the posting.',
            'evidence' => [
                ['excerpt' => 'Travel up to 25% required for client visits.', 'source_section' => 'Requirements', 'source_locator' => null],
                ['excerpt' => 'Travel is expected periodically to support on-site engagements.', 'source_section' => 'Requirements', 'source_locator' => null],
            ],
        ],
        [
            'category' => 'preferred_qualification',
            'statement' => 'ERP experience is not required',
            'label' => null,
            'basis' => 'explicit',
            'requirement_strength' => 'not_required',
            'emphasis' => 'normal',
            'maturity' => 'unspecified',
            'years_experience_min' => null,
            'years_experience_max' => null,
            'recency_requirement' => null,
            'time_horizon' => null,
            'notes' => null,
            'evidence' => [
                ['excerpt' => 'ERP experience is not required — we will train you on our systems.', 'source_section' => 'Requirements', 'source_locator' => null],
            ],
        ],
    ];

    foreach ($findingsWithEvidence as $findingData) {
        $finding = $analysis->findings()->create([
            'category' => $findingData['category'],
            'statement' => $findingData['statement'],
            'label' => $findingData['label'],
            'basis' => $findingData['basis'],
            'requirement_strength' => $findingData['requirement_strength'],
            'emphasis' => $findingData['emphasis'],
            'maturity' => $findingData['maturity'],
            'years_experience_min' => $findingData['years_experience_min'],
            'years_experience_max' => $findingData['years_experience_max'],
            'recency_requirement' => $findingData['recency_requirement'],
            'time_horizon' => $findingData['time_horizon'],
            'notes' => $findingData['notes'],
        ]);

        foreach ($findingData['evidence'] as $evidenceData) {
            $finding->evidence()->create($evidenceData);
        }
    }

    return $analysis->fresh();
}

it('renders the analysis detail page with job context and generation metadata', function () {
    $job = JobPosting::factory()->create(['company' => 'Acme Corp', 'title' => 'Senior Backend Engineer']);
    $analysis = persistedAnalysis($job);

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/analyses/show')
            ->where('job.id', $job->id)
            ->where('job.company', 'Acme Corp')
            ->where('job.title', 'Senior Backend Engineer')
            ->where('analysis.id', $analysis->id)
            ->where('analysis.generated_by', 'openai:gpt-5.6-test')
            ->where('analysis.schema_version', '1.0')
            ->where('analysis.prompt_version', 'job-analysis-v1')
            ->where('analysis.role_summary', 'A senior backend engineering role.')
            ->where('analysis.overall_seniority', 'senior')
            ->where('analysis.seniority_rationale', 'Explicitly titled Senior.')
        );
});

it('groups findings by category and includes every finding field and its evidence', function () {
    $analysis = persistedAnalysis();
    $job = $analysis->jobPosting;

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('analysis.categories', 3) // required_qualification, travel, preferred_qualification
            ->where('analysis.categories.0.category', 'required_qualification')
            ->has('analysis.categories.0.findings.0.evidence', 1)
            ->where('analysis.categories.0.findings.0.statement', '5+ years of backend engineering experience')
            ->where('analysis.categories.0.findings.0.basis', 'explicit')
            ->where('analysis.categories.0.findings.0.requirement_strength', 'required')
            ->where('analysis.categories.0.findings.0.years_experience_min', 5)
            ->where('analysis.categories.0.findings.0.evidence.0.excerpt', 'Minimum 5 years of backend engineering experience required.')
            ->where('analysis.categories.0.findings.0.evidence.0.source_section', 'Requirements')
        );
});

it('404s when the analysis does not belong to the given job posting', function () {
    $otherJob = JobPosting::factory()->create();
    $analysis = persistedAnalysis();

    $this->get(route('jobs.analyses.show', [$otherJob, $analysis]))
        ->assertNotFound();
});

it('404s for a nonexistent analysis id', function () {
    $job = JobPosting::factory()->create();

    $this->get("/jobs/{$job->id}/analyses/999999")->assertNotFound();
});

it('exposes no latest_job_match_attempt when none has ever been made', function () {
    $analysis = persistedAnalysis();
    $job = $analysis->jobPosting;

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertInertia(fn (Assert $page) => $page->where('analysis.latest_job_match_attempt', null));
});

it('exposes a queued attempt as the latest_job_match_attempt, so a reload shows generation in progress', function () {
    $analysis = persistedAnalysis();
    $job = $analysis->jobPosting;
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Queued,
    ]);

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.latest_job_match_attempt.id', $attempt->id)
            ->where('analysis.latest_job_match_attempt.status', 'queued')
        );
});

it('exposes the latest failed job_match attempt so a reload still explains why it failed', function () {
    $analysis = persistedAnalysis();
    $job = $analysis->jobPosting;
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Failed,
        'failure_message' => 'Match generation failed unexpectedly.',
    ]);

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.latest_job_match_attempt.status', 'failed')
            ->where('analysis.latest_job_match_attempt.failure_message', 'Match generation failed unexpectedly.')
        );
});

it('does not expose a succeeded job_match attempt as latest_job_match_attempt, since its result already appears in matches', function () {
    $analysis = persistedAnalysis();
    $job = $analysis->jobPosting;
    $profile = CareerProfile::factory()->create();
    $match = $analysis->jobMatches()->create([
        'career_profile_id' => $profile->id,
        'schema_version' => '1.0',
        'prompt_version' => 'job-match-v3',
        'generated_by' => 'openai:gpt-5.6-test',
        'generated_at' => now(),
        'input_snapshot' => [],
        'raw_response' => [],
    ]);
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Succeeded,
        'result_id' => $match->id,
    ]);

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertInertia(fn (Assert $page) => $page->where('analysis.latest_job_match_attempt', null));
});

it('does not select a job_analysis-typed attempt as this analysis own latest_job_match_attempt', function () {
    $analysis = persistedAnalysis();
    $job = $analysis->jobPosting;
    // A job_analysis attempt whose subject_id happens to equal this
    // JobAnalysis's own id — proves the query filters on generation_type,
    // not just subject_id, and (implicitly, via the polymorphic subject
    // relation) on subject_type.
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $job->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Running,
    ]);

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertInertia(fn (Assert $page) => $page->where('analysis.latest_job_match_attempt', null));
});

it('lists existing analyses newest first on the job posting detail page', function () {
    $job = JobPosting::factory()->create();
    // JobAnalysis is immutable — an older snapshot can never be
    // backdated after creation, so ordering is controlled by passing a
    // deliberately earlier generated_at at creation time instead.
    $older = persistedAnalysis($job, generatedAt: now()->subDay());
    $newer = persistedAnalysis($job, generatedAt: now());

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page
            ->has('job.analyses', 2)
            ->where('job.analyses.0.id', $newer->id)
            ->where('job.analyses.1.id', $older->id)
        );
});
