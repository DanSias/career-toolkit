<?php

use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\JobMatch;
use App\Models\Role;
use App\Models\Skill;
use App\Support\ResumeVariant\DirectEvidenceAuthorization;
use Tests\Support\ResumeVariantFixtures;

it('authorizes a direct claim when JobMatch already recorded a direct, attributed CareerFactMatch for that finding', function () {
    ['profile' => $profile, 'factIndependent' => $fact] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'ownershipFinding' => $finding, 'azureFinding' => $azureFinding] = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch($profile, $analysis, $azureFinding, $finding, CareerFact::factory()->create(['career_profile_id' => $profile->id]), $fact);

    expect(DirectEvidenceAuthorization::forFinding($match, $finding, 'Independently implemented the technical solution from team-provided requirements'))->toBeTrue();
});

it('does not authorize a direct claim from a transferable JobMatch citation alone', function () {
    ['profile' => $profile, 'factAws' => $factAws, 'factIndependent' => $factIndependent] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $azureFinding, 'ownershipFinding' => $ownershipFinding] = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch($profile, $analysis, $azureFinding, $ownershipFinding, $factAws, $factIndependent);

    // factAws is cited `transferable` (not `direct`) for the Azure finding.
    expect(DirectEvidenceAuthorization::forFinding($match, $azureFinding, 'Azure'))->toBeFalse();
});

it('falls back to an eligible, attributed CareerFact with the term as an attached Skill when JobMatch never cited anything for that finding', function () {
    ['profile' => $profile, 'role' => $role] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $azureFinding, 'ownershipFinding' => $ownershipFinding] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $skill = Skill::factory()->create(['career_profile_id' => $profile->id, 'name' => 'Kubernetes']);
    $fact = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'visibility' => Visibility::Public,
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $role->id,
    ]);
    $fact->skills()->attach($skill);

    expect(DirectEvidenceAuthorization::forFinding($match, $azureFinding, 'Kubernetes'))->toBeTrue();
});

it('does not authorize from a bare Skill with no supporting CareerFact', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $azureFinding] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    Skill::factory()->create(['career_profile_id' => $profile->id, 'name' => 'Terraform']);

    expect(DirectEvidenceAuthorization::forFinding($match, $azureFinding, 'Terraform'))->toBeFalse();
});

it('does not authorize from a CareerFact attributed directly to the CareerProfile (not a real Employer/Role/Project)', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $azureFinding] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $skill = Skill::factory()->create(['career_profile_id' => $profile->id, 'name' => 'Redis']);
    $fact = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'visibility' => Visibility::Public,
        // Default factory attribution is CareerProfile-direct.
    ]);
    $fact->skills()->attach($skill);

    expect(DirectEvidenceAuthorization::forFinding($match, $azureFinding, 'Redis'))->toBeFalse();
});

it('does not authorize from a Private CareerFact with the term as an attached Skill', function () {
    ['profile' => $profile, 'role' => $role] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $azureFinding] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $skill = Skill::factory()->create(['career_profile_id' => $profile->id, 'name' => 'Snowflake']);
    $fact = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'visibility' => Visibility::Private,
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $role->id,
    ]);
    $fact->skills()->attach($skill);

    expect(DirectEvidenceAuthorization::forFinding($match, $azureFinding, 'Snowflake'))->toBeFalse();
});
