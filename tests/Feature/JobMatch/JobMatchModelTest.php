<?php

use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use App\Exceptions\ImmutableJobMatchSnapshotException;
use App\Models\CareerFact;
use App\Models\CareerFactMatch;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\EducationMatch;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use Illuminate\Database\QueryException;
use Tests\Support\JobMatchFixtures;

// --- Relationships ---------------------------------------------------------

it('belongs to a real JobAnalysis and CareerProfile', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();
    ['analysis' => $analysis] = JobMatchFixtures::job();

    $match = JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
    ]);

    expect($match->jobAnalysis->is($analysis))->toBeTrue()
        ->and($match->careerProfile->is($profile))->toBeTrue();
});

it('lets a JobAnalysis and CareerProfile each own multiple JobMatch runs', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();
    ['analysis' => $analysis] = JobMatchFixtures::job();

    JobMatch::factory()->count(2)->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
    ]);

    expect($analysis->jobMatches()->count())->toBe(2)
        ->and($profile->jobMatches()->count())->toBe(2);
});

it('lets a JobMatchFinding own multiple CareerFactMatch and EducationMatch rows', function () {
    ['factOne' => $factOne, 'factTwo' => $factTwo, 'education' => $education] = JobMatchFixtures::candidate();
    $matchFinding = JobMatchFinding::factory()->create();

    $matchFinding->careerFactMatches()->create(['career_fact_id' => $factOne->id, 'relationship' => MatchRelationship::Direct, 'rationale' => 'a']);
    $matchFinding->careerFactMatches()->create(['career_fact_id' => $factTwo->id, 'relationship' => MatchRelationship::Contextual, 'rationale' => 'b']);
    $matchFinding->educationMatches()->create(['education_id' => $education->id, 'relationship' => MatchRelationship::Transferable, 'rationale' => 'c']);

    expect($matchFinding->careerFactMatches)->toHaveCount(2)
        ->and($matchFinding->educationMatches)->toHaveCount(1);
});

it('exposes the inverse relations on CareerFact and Education', function () {
    ['factOne' => $fact, 'education' => $education] = JobMatchFixtures::candidate();
    $matchFinding = JobMatchFinding::factory()->create();

    $matchFinding->careerFactMatches()->create(['career_fact_id' => $fact->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);
    $matchFinding->educationMatches()->create(['education_id' => $education->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);

    expect($fact->careerFactMatches()->count())->toBe(1)
        ->and($education->educationMatches()->count())->toBe(1);
});

// --- Completeness/uniqueness at the DB level --------------------------------

it('rejects a duplicate JobMatchFinding for the same JobAnalysisFinding within one JobMatch', function () {
    $match = JobMatch::factory()->create();
    $finding = JobAnalysisFinding::factory()->create();

    $match->findings()->create(['job_analysis_finding_id' => $finding->id, 'coverage' => JobMatchCoverage::Supported, 'coverage_rationale' => null]);

    $match->findings()->create(['job_analysis_finding_id' => $finding->id, 'coverage' => JobMatchCoverage::Partial, 'coverage_rationale' => null]);
})->throws(QueryException::class);

it('rejects a duplicate CareerFactMatch for the same CareerFact within one JobMatchFinding', function () {
    ['factOne' => $fact] = JobMatchFixtures::candidate();
    $matchFinding = JobMatchFinding::factory()->create();

    $matchFinding->careerFactMatches()->create(['career_fact_id' => $fact->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);
    $matchFinding->careerFactMatches()->create(['career_fact_id' => $fact->id, 'relationship' => MatchRelationship::Contextual, 'rationale' => null]);
})->throws(QueryException::class);

it('rejects a duplicate EducationMatch for the same Education within one JobMatchFinding', function () {
    ['education' => $education] = JobMatchFixtures::candidate();
    $matchFinding = JobMatchFinding::factory()->create();

    $matchFinding->educationMatches()->create(['education_id' => $education->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);
    $matchFinding->educationMatches()->create(['education_id' => $education->id, 'relationship' => MatchRelationship::Contextual, 'rationale' => null]);
})->throws(QueryException::class);

// --- Immutability ------------------------------------------------------------

it('rejects updates to an already-persisted JobMatch', function () {
    $match = JobMatch::factory()->create();

    $match->update(['schema_version' => 'x']);
})->throws(ImmutableJobMatchSnapshotException::class);

it('rejects updates to an already-persisted JobMatchFinding', function () {
    $finding = JobMatchFinding::factory()->create();

    $finding->update(['coverage_rationale' => 'x']);
})->throws(ImmutableJobMatchSnapshotException::class);

it('rejects updates to an already-persisted CareerFactMatch', function () {
    $match = CareerFactMatch::factory()->create();

    $match->update(['rationale' => 'x']);
})->throws(ImmutableJobMatchSnapshotException::class);

it('rejects updates to an already-persisted EducationMatch', function () {
    $match = EducationMatch::factory()->create();

    $match->update(['rationale' => 'x']);
})->throws(ImmutableJobMatchSnapshotException::class);

it('allows a full snapshot to be constructed in one flow without throwing', function () {
    ['factOne' => $fact, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $finding] = JobMatchFixtures::job();
    $profile = CareerProfile::find($fact->career_profile_id);

    $match = JobMatch::create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
        'schema_version' => '1.0',
        'prompt_version' => 'job-match-v1',
        'generated_by' => 'test',
        'generated_at' => now(),
        'input_snapshot' => ['candidate' => [], 'job' => []],
        'raw_response' => null,
    ]);
    $matchFinding = $match->findings()->create(['job_analysis_finding_id' => $finding->id, 'coverage' => JobMatchCoverage::Supported, 'coverage_rationale' => null]);
    $cfMatch = $matchFinding->careerFactMatches()->create(['career_fact_id' => $fact->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);
    $eduMatch = $matchFinding->educationMatches()->create(['education_id' => $education->id, 'relationship' => MatchRelationship::Contextual, 'rationale' => null]);

    expect($match->exists)->toBeTrue()
        ->and($matchFinding->exists)->toBeTrue()
        ->and($cfMatch->exists)->toBeTrue()
        ->and($eduMatch->exists)->toBeTrue();
});

// --- Deletion / FK behavior --------------------------------------------------

it('cascades deletes from JobMatch through findings and support references', function () {
    ['factOne' => $fact, 'education' => $education] = JobMatchFixtures::candidate();
    $match = JobMatch::factory()->create();
    $matchFinding = $match->findings()->create(['job_analysis_finding_id' => JobAnalysisFinding::factory()->create()->id, 'coverage' => JobMatchCoverage::Supported, 'coverage_rationale' => null]);
    $cfMatch = $matchFinding->careerFactMatches()->create(['career_fact_id' => $fact->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);
    $eduMatch = $matchFinding->educationMatches()->create(['education_id' => $education->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);

    $match->delete();

    expect(JobMatchFinding::find($matchFinding->id))->toBeNull()
        ->and(CareerFactMatch::find($cfMatch->id))->toBeNull()
        ->and(EducationMatch::find($eduMatch->id))->toBeNull();
});

it('does not cascade-delete a CareerFactMatch when its CareerFact is deleted — the delete is restricted instead', function () {
    ['factOne' => $fact] = JobMatchFixtures::candidate();
    $matchFinding = JobMatchFinding::factory()->create();
    $matchFinding->careerFactMatches()->create(['career_fact_id' => $fact->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);

    expect(fn () => $fact->delete())->toThrow(QueryException::class);
    expect(CareerFact::find($fact->id))->not->toBeNull();
});

it('does not cascade-delete an EducationMatch when its Education row is deleted — the delete is restricted instead', function () {
    ['education' => $education] = JobMatchFixtures::candidate();
    $matchFinding = JobMatchFinding::factory()->create();
    $matchFinding->educationMatches()->create(['education_id' => $education->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);

    expect(fn () => $education->delete())->toThrow(QueryException::class);
    expect(Education::find($education->id))->not->toBeNull();
});

it('still allows an unreferenced CareerFact or Education row to be deleted freely', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();
    $unreferencedFact = CareerFact::factory()->create(['career_profile_id' => $profile->id]);
    $unreferencedEducation = Education::factory()->create(['career_profile_id' => $profile->id]);

    $unreferencedFact->delete();
    $unreferencedEducation->delete();

    expect(CareerFact::find($unreferencedFact->id))->toBeNull()
        ->and(Education::find($unreferencedEducation->id))->toBeNull();
});

it('blocks deleting a CareerProfile that owns a CareerFact already cited by a JobMatch', function () {
    ['profile' => $profile, 'factOne' => $fact] = JobMatchFixtures::candidate();
    $matchFinding = JobMatchFinding::factory()->create();
    $matchFinding->careerFactMatches()->create(['career_fact_id' => $fact->id, 'relationship' => MatchRelationship::Direct, 'rationale' => null]);

    expect(fn () => $profile->delete())->toThrow(QueryException::class);
});

it('allows deleting a CareerProfile with no JobMatch history — a total wipe, consistent with existing precedent', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();

    $profile->delete();

    expect(CareerProfile::find($profile->id))->toBeNull();
});

it('cascades JobMatch deletion when its JobAnalysis is deleted', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id]);

    $analysis->delete();

    expect(JobMatch::find($match->id))->toBeNull();
});
