<?php

use App\Support\JobMatch\JobPayloadBuilder;
use Tests\Support\JobMatchFixtures;

it('includes role context and every finding belonging to the analysis', function () {
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    $payload = (new JobPayloadBuilder)->build($analysis);

    expect($payload)->toHaveKeys(['role_summary', 'overall_seniority', 'seniority_rationale', 'findings'])
        ->and($payload['findings'])->toHaveCount(2)
        ->and(array_column($payload['findings'], 'id'))->toEqualCanonicalizing([$findingOne->id, $findingTwo->id]);
});

it('includes only the approved normalized fields for each finding', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();

    $payload = (new JobPayloadBuilder)->build($analysis);

    expect(array_keys($payload['findings'][0]))->toEqualCanonicalizing([
        'id', 'category', 'statement', 'label', 'basis', 'requirement_strength',
        'emphasis', 'maturity', 'years_experience_min', 'years_experience_max',
        'recency_requirement', 'time_horizon', 'notes',
    ]);
});

it('never includes JobAnalysisFindingEvidence excerpts', function () {
    ['analysis' => $analysis, 'findingOne' => $finding] = JobMatchFixtures::job();
    $finding->evidence()->create(['excerpt' => 'a very distinctive posting excerpt never sent to the matcher', 'source_section' => null, 'source_locator' => null]);

    $payload = (new JobPayloadBuilder)->build($analysis);
    $json = json_encode($payload);

    expect($json)->not->toContain('a very distinctive posting excerpt');
});
