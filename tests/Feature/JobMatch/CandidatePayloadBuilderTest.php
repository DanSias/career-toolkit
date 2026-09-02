<?php

use App\Enums\MetricComparator;
use App\Models\CareerFact;
use App\Models\Employer;
use App\Models\Evidence;
use App\Models\Metric;
use App\Models\Project;
use App\Models\Role;
use App\Support\JobMatch\CandidatePayloadBuilder;
use Tests\Support\JobMatchFixtures;

it('includes every CareerFact and Education row belonging to the profile', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();

    $payload = (new CandidatePayloadBuilder)->build($profile);

    expect($payload['career_facts'])->toHaveCount(2)
        ->and($payload['education'])->toHaveCount(1);
});

it('includes only the approved normalized fields for each CareerFact', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();

    $payload = (new CandidatePayloadBuilder)->build($profile);
    $fact = $payload['career_facts'][0];

    expect(array_keys($fact))->toEqualCanonicalizing([
        'key', 'statement', 'fact_type', 'attribution', 'role_dates', 'metric', 'skills',
    ]);
});

it('includes only the approved normalized fields for each Education row', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();

    $payload = (new CandidatePayloadBuilder)->build($profile);
    $education = $payload['education'][0];

    expect(array_keys($education))->toEqualCanonicalizing([
        'id', 'institution', 'degree', 'field_of_study', 'start_year', 'end_year',
    ]);
});

it('never includes a raw database id for a CareerFact', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();

    $payload = (new CandidatePayloadBuilder)->build($profile);

    foreach ($payload['career_facts'] as $fact) {
        expect($fact)->not->toHaveKey('id');
    }
});

it('resolves employer/role/project attribution and role date context', function () {
    $employer = Employer::factory()->create();
    $role = Role::factory()->create(['employer_id' => $employer->id, 'start_year' => 2022, 'start_month' => 1, 'end_year' => 2024, 'end_month' => 6]);
    $project = Project::factory()->create(['role_id' => $role->id]);
    $fact = CareerFact::factory()->create([
        'career_profile_id' => $employer->career_profile_id,
        'attributable_type' => 'project',
        'attributable_id' => $project->id,
    ]);

    $payload = (new CandidatePayloadBuilder)->build($employer->careerProfile);
    $normalized = collect($payload['career_facts'])->firstWhere('key', $fact->key);

    expect($normalized['attribution'])->toBe([
        'employer' => $employer->name,
        'role' => $role->title,
        'project' => $project->name,
    ])
        ->and($normalized['role_dates'])->toBe([
            'start_year' => 2022,
            'start_month' => 1,
            'end_year' => 2024,
            'end_month' => 6,
        ]);
});

it('includes Metric fields including guardrail and scope_note when present', function () {
    $fact = CareerFact::factory()->create();
    Metric::factory()->create([
        'career_fact_id' => $fact->id,
        'value' => 43348,
        'value_max' => null,
        'unit' => 'transactions_analyzed',
        'comparator' => MetricComparator::Exact,
        'scope_note' => 'Final live audit population.',
        'guardrail' => 'Do not describe this as the number corrected.',
    ]);

    $payload = (new CandidatePayloadBuilder)->build($fact->careerProfile);
    $normalized = collect($payload['career_facts'])->firstWhere('key', $fact->key);

    expect($normalized['metric'])->toBe([
        'value' => 43348.0,
        'value_max' => null,
        'unit' => 'transactions_analyzed',
        'comparator' => 'exact',
        'scope_note' => 'Final live audit population.',
        'guardrail' => 'Do not describe this as the number corrected.',
    ]);
});

it('never includes raw Evidence in the candidate payload', function () {
    $fact = CareerFact::factory()->create();
    Evidence::factory()->create(['career_fact_id' => $fact->id, 'quoted_text' => 'superseded resume wording']);

    $payload = (new CandidatePayloadBuilder)->build($fact->careerProfile);
    $json = json_encode($payload);

    expect($json)->not->toContain('superseded resume wording');
});
