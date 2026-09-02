<?php

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingBasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobAnalysisSeniority;
use App\Exceptions\ImmutableJobAnalysisSnapshotException;
use App\Exceptions\InvalidJobAnalysisExperienceRangeException;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobAnalysisFindingEvidence;
use App\Models\JobPosting;

// --- Corpus-derived cases -------------------------------------------------

it('records a standard explicit required finding with one piece of evidence', function () {
    $analysis = JobAnalysis::factory()->create();
    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::RequiredQualification,
        'statement' => '5+ years of backend engineering experience',
        'basis' => JobAnalysisFindingBasis::Explicit,
        'requirement_strength' => JobAnalysisRequirementStrength::Required,
    ]);
    JobAnalysisFindingEvidence::factory()->for($finding, 'jobAnalysisFinding')->create([
        'excerpt' => 'Minimum 5 years of backend engineering experience required.',
    ]);

    expect($finding->basis)->toBe(JobAnalysisFindingBasis::Explicit)
        ->and($finding->requirement_strength)->toBe(JobAnalysisRequirementStrength::Required)
        ->and($finding->evidence)->toHaveCount(1);
});

it('records an explicit not_required finding, distinct from silence', function () {
    // Mirrors Rootstock's explicit ERP disclaimer: the posting actively
    // says this is not required, which is different information than
    // the posting simply never mentioning it.
    $finding = JobAnalysisFinding::factory()->notRequired()->create([
        'category' => JobAnalysisFindingCategory::PreferredQualification,
        'statement' => 'ERP experience is not required for this role',
        'basis' => JobAnalysisFindingBasis::Explicit,
    ]);

    expect($finding->requirement_strength)->toBe(JobAnalysisRequirementStrength::NotRequired);
});

it('allows a null requirement_strength for an ambiguous finding', function () {
    // Mirrors Intent Design's ambiguous block — the posting mentions
    // something without stating whether it's required, preferred, or
    // explicitly not required.
    $finding = JobAnalysisFinding::factory()->ambiguousStrength()->create([
        'category' => JobAnalysisFindingCategory::DomainKnowledge,
        'statement' => 'Familiarity with design systems is a plus in some contexts',
        'basis' => JobAnalysisFindingBasis::Explicit,
    ]);

    expect($finding->requirement_strength)->toBeNull();
});

it('lets a repeated high-emphasis finding carry multiple evidence rows', function () {
    // Mirrors Vista's travel requirement, stated in more than one
    // section of the posting.
    $finding = JobAnalysisFinding::factory()->highEmphasis()->create([
        'statement' => 'Travel up to 25% required',
    ]);
    JobAnalysisFindingEvidence::factory()->for($finding, 'jobAnalysisFinding')->create([
        'excerpt' => 'Must be willing to travel up to 25% of the time.',
        'source_section' => 'Requirements',
    ]);
    JobAnalysisFindingEvidence::factory()->for($finding, 'jobAnalysisFinding')->create([
        'excerpt' => 'This role includes regular travel for client visits.',
        'source_section' => 'About the Role',
    ]);

    expect($finding->emphasis)->toBe(JobAnalysisEmphasis::High)
        ->and($finding->evidence)->toHaveCount(2)
        ->and($finding->evidence->pluck('source_section')->all())->toEqual(['Requirements', 'About the Role']);
});

it('stores an experience floor as a min with no max', function () {
    // "5+ years" — a floor, not a range.
    $finding = JobAnalysisFinding::factory()->experienceFloor(5.0)->create();

    expect($finding->years_experience_min)->toBe(5.0)
        ->and($finding->years_experience_max)->toBeNull();
});

it('stores an expressed experience range as both a min and a max', function () {
    // "7-10 years"
    $finding = JobAnalysisFinding::factory()->experienceRange(7.0, 10.0)->create();

    expect($finding->years_experience_min)->toBe(7.0)
        ->and($finding->years_experience_max)->toBe(10.0);
});

it('lets an inferred finding carry multiple supporting evidence rows', function () {
    $finding = JobAnalysisFinding::factory()->inferred()->create([
        'category' => JobAnalysisFindingCategory::CultureSignal,
        'statement' => 'Fast-paced, startup-style environment is strongly implied',
        'basis' => JobAnalysisFindingBasis::Inferred,
    ]);
    JobAnalysisFindingEvidence::factory()->for($finding, 'jobAnalysisFinding')->count(2)->create();

    expect($finding->basis)->toBe(JobAnalysisFindingBasis::Inferred)
        ->and($finding->evidence)->toHaveCount(2);
});

it('lets one job posting accumulate multiple job analysis snapshots', function () {
    $posting = JobPosting::factory()->create();

    JobAnalysis::factory()->for($posting)->create(['schema_version' => '1.0']);
    JobAnalysis::factory()->for($posting)->create(['schema_version' => '1.0']);

    expect($posting->jobAnalyses)->toHaveCount(2)
        ->and(JobPosting::find($posting->id)->getAttributes())->not->toHaveKey('current_job_analysis_id');
});

// --- Relationships ----------------------------------------------------------

it('belongs to a real job posting via a foreign key', function () {
    $posting = JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->for($posting)->create();

    expect($analysis->jobPosting->is($posting))->toBeTrue();
});

it('lets a job analysis own multiple findings', function () {
    $analysis = JobAnalysis::factory()->create();
    JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->count(3)->create();

    expect($analysis->findings)->toHaveCount(3);
});

it('lets a finding own multiple evidence rows', function () {
    $finding = JobAnalysisFinding::factory()->create();
    JobAnalysisFindingEvidence::factory()->for($finding, 'jobAnalysisFinding')->count(3)->create();

    expect($finding->evidence)->toHaveCount(3);
});

// --- Construction is never blocked ------------------------------------------

it('lets a full snapshot be constructed in one flow without throwing', function () {
    $posting = JobPosting::factory()->create();

    $analysis = $posting->jobAnalyses()->create(JobAnalysis::factory()->make()->toArray());
    $finding = $analysis->findings()->create(JobAnalysisFinding::factory()->make(['job_analysis_id' => $analysis->id])->toArray());
    $evidence = $finding->evidence()->create(['excerpt' => 'quoted text', 'source_section' => 'Requirements']);

    expect($analysis->exists)->toBeTrue()
        ->and($finding->exists)->toBeTrue()
        ->and($evidence->exists)->toBeTrue();
});

// --- Immutability ------------------------------------------------------------

it('rejects updates to an already-persisted job analysis', function () {
    $analysis = JobAnalysis::factory()->create();

    $analysis->update(['role_summary' => 'changed after the fact']);
})->throws(ImmutableJobAnalysisSnapshotException::class);

it('rejects updates to an already-persisted finding', function () {
    $finding = JobAnalysisFinding::factory()->create();

    $finding->update(['statement' => 'changed after the fact']);
})->throws(ImmutableJobAnalysisSnapshotException::class);

it('rejects updates to already-persisted evidence', function () {
    $evidence = JobAnalysisFindingEvidence::factory()->create();

    $evidence->update(['excerpt' => 'changed after the fact']);
})->throws(ImmutableJobAnalysisSnapshotException::class);

// --- Experience-range validation ---------------------------------------------

it('rejects a negative years_experience_min', function () {
    JobAnalysisFinding::factory()->create(['years_experience_min' => -1]);
})->throws(InvalidJobAnalysisExperienceRangeException::class);

it('rejects a negative years_experience_max', function () {
    JobAnalysisFinding::factory()->create(['years_experience_max' => -1]);
})->throws(InvalidJobAnalysisExperienceRangeException::class);

it('rejects a years_experience_max less than years_experience_min', function () {
    JobAnalysisFinding::factory()->create(['years_experience_min' => 5, 'years_experience_max' => 3]);
})->throws(InvalidJobAnalysisExperienceRangeException::class);

it('allows a valid experience floor with no max', function () {
    $finding = JobAnalysisFinding::factory()->create(['years_experience_min' => 5, 'years_experience_max' => null]);

    expect($finding->years_experience_min)->toBe(5.0);
});

it('allows a valid experience range where max is greater than min', function () {
    $finding = JobAnalysisFinding::factory()->create(['years_experience_min' => 3, 'years_experience_max' => 6]);

    expect($finding->years_experience_max)->toBe(6.0);
});

// --- Cascading deletes ---------------------------------------------------------

it('cascades deletes from job posting through analysis, finding, and evidence', function () {
    $posting = JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->for($posting)->create();
    $finding = JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->create();
    $evidence = JobAnalysisFindingEvidence::factory()->for($finding, 'jobAnalysisFinding')->create();

    $posting->delete();

    expect(JobAnalysis::find($analysis->id))->toBeNull()
        ->and(JobAnalysisFinding::find($finding->id))->toBeNull()
        ->and(JobAnalysisFindingEvidence::find($evidence->id))->toBeNull();
});

it('cascades deletes from job analysis through its findings and evidence', function () {
    $analysis = JobAnalysis::factory()->create();
    $finding = JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->create();
    $evidence = JobAnalysisFindingEvidence::factory()->for($finding, 'jobAnalysisFinding')->create();

    $analysis->delete();

    expect(JobAnalysisFinding::find($finding->id))->toBeNull()
        ->and(JobAnalysisFindingEvidence::find($evidence->id))->toBeNull();
});

it('cascades deletes from finding to its evidence, leaving the analysis intact', function () {
    $analysis = JobAnalysis::factory()->create();
    $finding = JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->create();
    $evidence = JobAnalysisFindingEvidence::factory()->for($finding, 'jobAnalysisFinding')->create();

    $finding->delete();

    expect(JobAnalysisFindingEvidence::find($evidence->id))->toBeNull()
        ->and(JobAnalysis::find($analysis->id))->not->toBeNull();
});

// --- Candidate independence ------------------------------------------------

it('holds no relationship to any candidate-side model', function () {
    $forbidden = ['CareerFact', 'Skill', 'Project', 'Employer', 'Role'];

    foreach ([JobAnalysis::class, JobAnalysisFinding::class, JobAnalysisFindingEvidence::class] as $model) {
        $reflection = new ReflectionClass($model);
        $methodNames = array_map(fn (ReflectionMethod $m) => $m->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC));

        foreach ($forbidden as $name) {
            expect($methodNames)->not->toContain(lcfirst($name))
                ->and($methodNames)->not->toContain(lcfirst($name).'s');
        }
    }
});

it('never generates a JobAnalysis with overall_seniority set to anything but a valid enum case', function () {
    $analysis = JobAnalysis::factory()->create(['overall_seniority' => JobAnalysisSeniority::StaffOrAbove]);

    expect($analysis->overall_seniority)->toBeInstanceOf(JobAnalysisSeniority::class);
});
