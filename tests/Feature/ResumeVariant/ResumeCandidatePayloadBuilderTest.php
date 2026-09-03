<?php

use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\JobMatch;
use App\Models\Project;
use App\Models\Role;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use Illuminate\Support\Facades\Artisan;

/**
 * Guards the payload-grounding fix made after two live Pearly failures
 * where Resume Selection repeatedly attached one Pearson role's real
 * title/projects to its sibling Pearson role's role_id. The root cause
 * (see docs/resume-variant-generation.md "Live evaluation"): role_id/
 * project_id previously had no textual grounding anywhere in the
 * candidate payload — only career_fact_key did, and it was never
 * misattributed once across either failure. The fix adds real
 * role_id/project_id directly into each fact's `attribution`, resolved
 * from the fact's actual loaded `attributable` relation only.
 */
function pearsonSiblingRoleCandidate(): array
{
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->create(['career_profile_id' => $profile->id, 'name' => 'Acme Co']);

    // Two sibling roles at the SAME employer — the exact shape that
    // failed live: adjacent, same-employer, easily confusable by name
    // alone without an explicit id.
    $roleA = Role::factory()->create([
        'employer_id' => $employer->id,
        'title' => 'Support Analyst',
        'start_year' => 2013,
        'end_year' => 2015,
    ]);
    $roleB = Role::factory()->create([
        'employer_id' => $employer->id,
        'title' => 'Lead Developer / Analyst',
        'start_year' => 2015,
        'end_year' => null,
    ]);
    $projectB = Project::factory()->create(['role_id' => $roleB->id, 'name' => 'Analytics Platform']);

    $factRoleLevelOnRoleA = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'key' => 'fixture-role-a-role-level',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $roleA->id,
        'visibility' => Visibility::Public,
    ]);
    $factRoleLevelOnRoleB = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'key' => 'fixture-role-b-role-level',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $roleB->id,
        'visibility' => Visibility::Public,
    ]);
    $factProjectOnRoleB = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'key' => 'fixture-role-b-project-level',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $projectB->id,
        'visibility' => Visibility::Public,
    ]);

    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id]);

    return compact('profile', 'roleA', 'roleB', 'projectB', 'factRoleLevelOnRoleA', 'factRoleLevelOnRoleB', 'factProjectOnRoleB', 'match');
}

it('grounds a Role-attributed CareerFact with its actual canonical role_id and a null project_id', function () {
    ['roleA' => $roleA, 'factRoleLevelOnRoleA' => $fact, 'match' => $match] = pearsonSiblingRoleCandidate();

    $payload = (new ResumeCandidatePayloadBuilder)->build($match);
    $entry = collect($payload['career_facts'])->firstWhere('key', $fact->key);

    expect($entry['attribution']['role_id'])->toBe($roleA->id)
        ->and($entry['attribution']['project_id'])->toBeNull();
});

it('grounds a Project-attributed CareerFact with both the owning role_id and the project_id', function () {
    ['roleB' => $roleB, 'projectB' => $projectB, 'factProjectOnRoleB' => $fact, 'match' => $match] = pearsonSiblingRoleCandidate();

    $payload = (new ResumeCandidatePayloadBuilder)->build($match);
    $entry = collect($payload['career_facts'])->firstWhere('key', $fact->key);

    expect($entry['attribution']['role_id'])->toBe($roleB->id)
        ->and($entry['attribution']['project_id'])->toBe($projectB->id);
});

it('never swaps sibling roles at the same employer — each fact carries only its own role_id', function () {
    [
        'roleA' => $roleA, 'roleB' => $roleB,
        'factRoleLevelOnRoleA' => $factA, 'factRoleLevelOnRoleB' => $factB,
        'match' => $match,
    ] = pearsonSiblingRoleCandidate();

    $payload = (new ResumeCandidatePayloadBuilder)->build($match);
    $entryA = collect($payload['career_facts'])->firstWhere('key', $factA->key);
    $entryB = collect($payload['career_facts'])->firstWhere('key', $factB->key);

    expect($entryA['attribution']['role_id'])->toBe($roleA->id)
        ->and($entryA['attribution']['role_id'])->not->toBe($roleB->id)
        ->and($entryB['attribution']['role_id'])->toBe($roleB->id)
        ->and($entryB['attribution']['role_id'])->not->toBe($roleA->id);
});

it('resolves ids from the actual loaded attributable relation, not array/serialization position', function () {
    // Deliberately create Role B's fact BEFORE Role A's in the DB (reverse
    // of declaration order below) to rule out any "first array entry gets
    // the first role" or insertion-order-derived id assignment.
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->create(['career_profile_id' => $profile->id]);
    $roleLater = Role::factory()->create(['employer_id' => $employer->id, 'start_year' => 2020]);
    $roleEarlier = Role::factory()->create(['employer_id' => $employer->id, 'start_year' => 2010]);

    $factOnEarlier = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'key' => 'fixture-on-earlier-role',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $roleEarlier->id,
        'visibility' => Visibility::Public,
    ]);
    $factOnLater = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'key' => 'fixture-on-later-role',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $roleLater->id,
        'visibility' => Visibility::Public,
    ]);

    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id]);
    $payload = (new ResumeCandidatePayloadBuilder)->build($match);

    $entryEarlier = collect($payload['career_facts'])->firstWhere('key', $factOnEarlier->key);
    $entryLater = collect($payload['career_facts'])->firstWhere('key', $factOnLater->key);

    // roleLater was created FIRST in the DB (lower id) despite being the
    // chronologically later role — proves the payload's role_id tracks
    // the real attributable_id, never chronology or creation order.
    expect($roleLater->id)->toBeLessThan($roleEarlier->id)
        ->and($entryEarlier['attribution']['role_id'])->toBe($roleEarlier->id)
        ->and($entryLater['attribution']['role_id'])->toBe($roleLater->id);
});

it('excludes Private facts from the payload exactly as before this change', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->create(['career_profile_id' => $profile->id]);
    $role = Role::factory()->create(['employer_id' => $employer->id]);

    $privateFact = CareerFact::factory()->create([
        'career_profile_id' => $profile->id,
        'key' => 'fixture-private-fact',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $role->id,
        'visibility' => Visibility::Private,
    ]);

    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id]);
    $payload = (new ResumeCandidatePayloadBuilder)->build($match);

    expect(collect($payload['career_facts'])->pluck('key'))->not->toContain($privateFact->key);
});

it('grounds the real Pearson roles/projects correctly against the imported canonical dataset', function () {
    Artisan::call('career:import');

    $profile = CareerProfile::query()->oldest('id')->first();
    $roleDataAnalyticsLead = Role::where('title', 'Data & Analytics Lead Developer / Data Analyst')->first();
    $roleSeoAnalyst = Role::where('title', 'Search Engine Optimization Analyst')->first();
    $nexusProject = Project::where('name', 'Nexus: Analytics Command Center')->first();

    expect($roleDataAnalyticsLead)->not->toBeNull()
        ->and($roleSeoAnalyst)->not->toBeNull()
        ->and($nexusProject)->not->toBeNull()
        ->and($roleDataAnalyticsLead->id)->not->toBe($roleSeoAnalyst->id);

    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id]);
    $payload = (new ResumeCandidatePayloadBuilder)->build($match);
    $factsByKey = collect($payload['career_facts'])->keyBy('key');

    // SEO facts identify the SEO role, never the sibling Data & Analytics role.
    $seoFact = $factsByKey->get('pearson-seo-analyst-what-they-did');
    expect($seoFact['attribution']['role_id'])->toBe($roleSeoAnalyst->id)
        ->and($seoFact['attribution']['role_id'])->not->toBe($roleDataAnalyticsLead->id)
        ->and($seoFact['attribution']['project_id'])->toBeNull();

    // Data & Analytics Lead role-level fact identifies that role.
    $dataAnalyticsFact = $factsByKey->get('pearson-data-analytics-lead-stakeholder-partnership');
    expect($dataAnalyticsFact['attribution']['role_id'])->toBe($roleDataAnalyticsLead->id)
        ->and($dataAnalyticsFact['attribution']['project_id'])->toBeNull();

    // A Nexus (project-level) fact exposes the owning role_id AND its own project_id.
    $nexusFact = $factsByKey->get('pearson-nexus-what-it-is');
    expect($nexusFact['attribution']['role_id'])->toBe($roleDataAnalyticsLead->id)
        ->and($nexusFact['attribution']['project_id'])->toBe($nexusProject->id);
});
