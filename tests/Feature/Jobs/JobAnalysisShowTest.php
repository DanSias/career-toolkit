<?php

use App\Enums\JobAnalysisSeniority;
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

    foreach (JobAnalysisFixtures::validPayload()['findings'] as $findingData) {
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
