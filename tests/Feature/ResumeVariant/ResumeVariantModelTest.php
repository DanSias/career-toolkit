<?php

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Exceptions\ImmutableResumeVariantSnapshotException;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Employer;
use App\Models\JobMatch;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantBulletCitation;
use App\Models\ResumeVariantEducationSelection;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\ResumeVariantSkillSelection;
use App\Models\ResumeVariantSummaryEvidence;
use App\Models\ResumeVariantTargetTermUsage;
use App\Models\ResumeVariantTargetTermUsageEvidence;
use App\Models\Role;
use App\Models\Skill;
use Illuminate\Database\QueryException;
use Tests\Support\ResumeVariantFixtures;

// --- Relationships ---------------------------------------------------------

it('belongs to a real CareerProfile and JobMatch', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $variant = ResumeVariant::factory()->create(['career_profile_id' => $profile->id, 'job_match_id' => $match->id]);

    expect($variant->careerProfile->is($profile))->toBeTrue()
        ->and($variant->jobMatch->is($match))->toBeTrue();
});

it('lets a CareerProfile and JobMatch each own multiple ResumeVariant generations', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    ResumeVariant::factory()->count(2)->create(['career_profile_id' => $profile->id, 'job_match_id' => $match->id]);

    expect($profile->resumeVariants()->count())->toBe(2)
        ->and($match->resumeVariants()->count())->toBe(2);
});

it('builds the full relational tree: bullets, citations, summary evidence, skills, education, target-term usages', function () {
    ['profile' => $profile, 'role' => $role, 'employer' => $employer, 'project' => $project, 'factAws' => $factAws, 'awsSkill' => $awsSkill, 'education' => $education] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $azureFinding] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);
    $variant = ResumeVariant::factory()->create(['career_profile_id' => $profile->id, 'job_match_id' => $match->id]);

    $bullet = $variant->experienceBullets()->create([
        'employer_id' => $employer->id, 'role_id' => $role->id, 'project_id' => $project->id,
        'display_title' => $role->title, 'display_order' => 1, 'text' => 'Built things on AWS.',
    ]);
    $bullet->citations()->create(['career_fact_id' => $factAws->id]);
    $variant->summaryEvidence()->create(['career_fact_id' => $factAws->id]);
    $variant->skillSelections()->create(['skill_id' => $awsSkill->id, 'display_order' => 1]);
    $variant->educationSelections()->create(['education_id' => $education->id, 'display_order' => 1]);
    $usage = $variant->targetTermUsages()->create([
        'bullet_id' => $bullet->id, 'job_analysis_finding_id' => $azureFinding->id,
        'target_term' => 'Azure', 'posture' => ResumeClaimPosture::Qualified,
        'location' => ResumeTermUsageLocation::Bullet, 'relationship_phrase_key' => ResumeQualifiedPhrase::ApplicableTo,
    ]);
    $usage->evidence()->create(['career_fact_id' => $factAws->id]);

    expect($variant->experienceBullets)->toHaveCount(1)
        ->and($bullet->citations)->toHaveCount(1)
        ->and($variant->summaryEvidence)->toHaveCount(1)
        ->and($variant->skillSelections)->toHaveCount(1)
        ->and($variant->educationSelections)->toHaveCount(1)
        ->and($variant->targetTermUsages)->toHaveCount(1)
        ->and($usage->evidence)->toHaveCount(1)
        ->and($usage->bullet->is($bullet))->toBeTrue();
});

// --- Uniqueness --------------------------------------------------------------

it('rejects a duplicate bullet citation for the same CareerFact within one bullet', function () {
    ['factAws' => $fact] = ResumeVariantFixtures::candidate();
    $bullet = ResumeVariantExperienceBullet::factory()->create();

    $bullet->citations()->create(['career_fact_id' => $fact->id]);
    $bullet->citations()->create(['career_fact_id' => $fact->id]);
})->throws(QueryException::class);

it('rejects a duplicate skill selection within one ResumeVariant', function () {
    ['awsSkill' => $skill] = ResumeVariantFixtures::candidate();
    $variant = ResumeVariant::factory()->create();

    $variant->skillSelections()->create(['skill_id' => $skill->id, 'display_order' => 1]);
    $variant->skillSelections()->create(['skill_id' => $skill->id, 'display_order' => 2]);
})->throws(QueryException::class);

it('rejects a duplicate education selection within one ResumeVariant', function () {
    ['education' => $education] = ResumeVariantFixtures::candidate();
    $variant = ResumeVariant::factory()->create();

    $variant->educationSelections()->create(['education_id' => $education->id, 'display_order' => 1]);
    $variant->educationSelections()->create(['education_id' => $education->id, 'display_order' => 2]);
})->throws(QueryException::class);

// --- Immutability --------------------------------------------------------------

it('rejects updates to an already-persisted ResumeVariant', function () {
    $variant = ResumeVariant::factory()->create();

    $variant->update(['summary' => 'x']);
})->throws(ImmutableResumeVariantSnapshotException::class);

it('rejects updates to an already-persisted ResumeVariantExperienceBullet', function () {
    $bullet = ResumeVariantExperienceBullet::factory()->create();

    $bullet->update(['text' => 'x']);
})->throws(ImmutableResumeVariantSnapshotException::class);

it('rejects updates to an already-persisted ResumeVariantBulletCitation', function () {
    $citation = ResumeVariantBulletCitation::factory()->create();

    $citation->update(['career_fact_id' => CareerFact::factory()->create()->id]);
})->throws(ImmutableResumeVariantSnapshotException::class);

it('rejects updates to an already-persisted ResumeVariantSummaryEvidence row', function () {
    $evidence = ResumeVariantSummaryEvidence::factory()->create();

    $evidence->update(['career_fact_id' => CareerFact::factory()->create()->id]);
})->throws(ImmutableResumeVariantSnapshotException::class);

it('rejects updates to an already-persisted ResumeVariantSkillSelection', function () {
    $selection = ResumeVariantSkillSelection::factory()->create();

    $selection->update(['display_order' => 99]);
})->throws(ImmutableResumeVariantSnapshotException::class);

it('rejects updates to an already-persisted ResumeVariantEducationSelection', function () {
    $selection = ResumeVariantEducationSelection::factory()->create();

    $selection->update(['display_order' => 99]);
})->throws(ImmutableResumeVariantSnapshotException::class);

it('rejects updates to an already-persisted ResumeVariantTargetTermUsage', function () {
    $usage = ResumeVariantTargetTermUsage::factory()->create();

    $usage->update(['target_term' => 'Something else']);
})->throws(ImmutableResumeVariantSnapshotException::class);

it('rejects updates to an already-persisted ResumeVariantTargetTermUsageEvidence row', function () {
    $evidence = ResumeVariantTargetTermUsageEvidence::factory()->create();

    $evidence->update(['career_fact_id' => CareerFact::factory()->create()->id]);
})->throws(ImmutableResumeVariantSnapshotException::class);

// --- Deletion / FK behavior --------------------------------------------------

it('cascades deletes from ResumeVariant through its full tree', function () {
    ['profile' => $profile, 'role' => $role, 'employer' => $employer, 'factAws' => $factAws, 'awsSkill' => $skill, 'education' => $education] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $finding] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);
    $variant = ResumeVariant::factory()->create(['career_profile_id' => $profile->id, 'job_match_id' => $match->id]);

    $bullet = $variant->experienceBullets()->create([
        'employer_id' => $employer->id, 'role_id' => $role->id, 'project_id' => null,
        'display_title' => $role->title, 'display_order' => 1, 'text' => 'x',
    ]);
    $citation = $bullet->citations()->create(['career_fact_id' => $factAws->id]);
    $summaryEvidence = $variant->summaryEvidence()->create(['career_fact_id' => $factAws->id]);
    $skillSelection = $variant->skillSelections()->create(['skill_id' => $skill->id, 'display_order' => 1]);
    $educationSelection = $variant->educationSelections()->create(['education_id' => $education->id, 'display_order' => 1]);
    $usage = $variant->targetTermUsages()->create([
        'bullet_id' => $bullet->id, 'job_analysis_finding_id' => $finding->id,
        'target_term' => 'Azure', 'posture' => ResumeClaimPosture::Capability,
        'location' => ResumeTermUsageLocation::Bullet, 'relationship_phrase_key' => ResumeQualifiedPhrase::NotApplicable,
    ]);
    $usageEvidence = $usage->evidence()->create(['career_fact_id' => $factAws->id]);

    $variant->delete();

    expect(ResumeVariantExperienceBullet::find($bullet->id))->toBeNull()
        ->and(ResumeVariantBulletCitation::find($citation->id))->toBeNull()
        ->and(ResumeVariantSummaryEvidence::find($summaryEvidence->id))->toBeNull()
        ->and(ResumeVariantSkillSelection::find($skillSelection->id))->toBeNull()
        ->and(ResumeVariantEducationSelection::find($educationSelection->id))->toBeNull()
        ->and(ResumeVariantTargetTermUsage::find($usage->id))->toBeNull()
        ->and(ResumeVariantTargetTermUsageEvidence::find($usageEvidence->id))->toBeNull();
});

it('does not cascade-delete a bullet citation when its CareerFact is deleted — the delete is restricted instead', function () {
    ['factAws' => $fact] = ResumeVariantFixtures::candidate();
    $bullet = ResumeVariantExperienceBullet::factory()->create();
    $bullet->citations()->create(['career_fact_id' => $fact->id]);

    expect(fn () => $fact->delete())->toThrow(QueryException::class);
    expect(CareerFact::find($fact->id))->not->toBeNull();
});

it('does not cascade-delete a skill selection when its Skill is deleted — the delete is restricted instead', function () {
    ['awsSkill' => $skill] = ResumeVariantFixtures::candidate();
    $variant = ResumeVariant::factory()->create();
    $variant->skillSelections()->create(['skill_id' => $skill->id, 'display_order' => 1]);

    expect(fn () => $skill->delete())->toThrow(QueryException::class);
    expect(Skill::find($skill->id))->not->toBeNull();
});

it('does not cascade-delete an education selection when its Education is deleted — the delete is restricted instead', function () {
    ['education' => $education] = ResumeVariantFixtures::candidate();
    $variant = ResumeVariant::factory()->create();
    $variant->educationSelections()->create(['education_id' => $education->id, 'display_order' => 1]);

    expect(fn () => $education->delete())->toThrow(QueryException::class);
    expect(Education::find($education->id))->not->toBeNull();
});

it('does not cascade-delete a bullet when its Employer/Role is deleted — the delete is restricted instead', function () {
    ['profile' => $profile, 'role' => $role, 'employer' => $employer] = ResumeVariantFixtures::candidate();
    $variant = ResumeVariant::factory()->create(['career_profile_id' => $profile->id]);
    $variant->experienceBullets()->create([
        'employer_id' => $employer->id, 'role_id' => $role->id, 'project_id' => null,
        'display_title' => $role->title, 'display_order' => 1, 'text' => 'x',
    ]);

    expect(fn () => $role->delete())->toThrow(QueryException::class);
    expect(Role::find($role->id))->not->toBeNull();
});

it('does not cascade-delete a ResumeVariant when its JobMatch is deleted — the delete is restricted instead', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);
    ResumeVariant::factory()->create(['career_profile_id' => $profile->id, 'job_match_id' => $match->id]);

    expect(fn () => $match->delete())->toThrow(QueryException::class);
    expect(JobMatch::find($match->id))->not->toBeNull();
});

it('still allows an unreferenced CareerFact, Skill, or Education row to be deleted freely', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    $unreferencedFact = CareerFact::factory()->create(['career_profile_id' => $profile->id]);
    $unreferencedSkill = Skill::factory()->create(['career_profile_id' => $profile->id]);
    $unreferencedEducation = Education::factory()->create(['career_profile_id' => $profile->id]);

    $unreferencedFact->delete();
    $unreferencedSkill->delete();
    $unreferencedEducation->delete();

    expect(CareerFact::find($unreferencedFact->id))->toBeNull()
        ->and(Skill::find($unreferencedSkill->id))->toBeNull()
        ->and(Education::find($unreferencedEducation->id))->toBeNull();
});

// Unlike JobMatch's own equivalent test (which incidentally cites the
// fact from an unrelated, factory-default profile), a CareerFact and
// the ResumeVariant that legitimately cites it always belong to the
// SAME profile in real usage — Selection only ever draws from one
// profile's own eligible corpus. Deleting that profile therefore
// cascades cleanly through both the CareerFact (career_facts.
// career_profile_id cascadeOnDelete) and the citing ResumeVariant
// (resume_variants.career_profile_id cascadeOnDelete) at once, with no
// RESTRICT conflict — a coherent, self-contained profile wipe, not a
// gap in the protection. The RESTRICT itself is verified directly
// above ("does not cascade-delete a bullet citation when its
// CareerFact is deleted") and continues to protect against the real
// risk: deleting a CareerFact (or Skill/Education/Role) individually
// out from under history that still needs it.
it('allows a full CareerProfile wipe even when it owns both a CareerFact and the ResumeVariant that cites it', function () {
    ['profile' => $profile, 'factAws' => $fact] = ResumeVariantFixtures::candidate();
    $variant = ResumeVariant::factory()->create(['career_profile_id' => $profile->id]);
    $variant->summaryEvidence()->create(['career_fact_id' => $fact->id]);

    $profile->delete();

    expect(CareerProfile::find($profile->id))->toBeNull()
        ->and(ResumeVariant::find($variant->id))->toBeNull()
        ->and(CareerFact::find($fact->id))->toBeNull();
});

it('allows deleting a CareerProfile with no ResumeVariant history — a total wipe, consistent with existing precedent', function () {
    $profile = CareerProfile::factory()->create();

    $profile->delete();

    expect(CareerProfile::find($profile->id))->toBeNull();
});

it('cascades ResumeVariant deletion when its CareerProfile is deleted', function () {
    // No canonical citations here, only the profile ownership FK itself
    // (career_profile_id cascadeOnDelete) — deliberately distinct from
    // job_match_id (restrictOnDelete), mirroring JobMatch's own two-tier
    // deletion behavior.
    $employer = Employer::factory()->create();
    $variant = ResumeVariant::factory()->create(['career_profile_id' => $employer->career_profile_id]);

    $employer->careerProfile->delete();

    expect(ResumeVariant::find($variant->id))->toBeNull();
});
