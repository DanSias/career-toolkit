<?php

use App\Support\JobMatch\CandidatePayloadBuilder;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\JobMatch\Prompts\JobMatchPromptV1;
use Tests\Support\JobMatchFixtures;

it('versions independently of JobAnalysis', function () {
    $prompt = new JobMatchPromptV1;

    expect($prompt->version())->toBe('job-match-v1')
        ->and($prompt->schemaVersion())->toBe('1.0');
});

it('retains its original no_evidence wording, unmodified by JobMatchPromptV2\'s clarification', function () {
    $systemPrompt = (new JobMatchPromptV1)->systemPrompt();

    expect($systemPrompt)->toContain("contains no\n  meaningful evidence addressing this finding")
        ->and($systemPrompt)->not->toContain('If you find yourself citing any `career_fact_key` or `education_id`');
});

it('embeds the candidate and job payloads in the user prompt', function () {
    ['profile' => $profile, 'factOne' => $fact] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $finding] = JobMatchFixtures::job();

    $candidatePayload = (new CandidatePayloadBuilder)->build($profile);
    $jobPayload = (new JobPayloadBuilder)->build($analysis);

    $userPrompt = (new JobMatchPromptV1)->userPrompt($candidatePayload, $jobPayload);

    expect($userPrompt)
        ->toContain($fact->key)
        ->toContain($fact->statement)
        ->toContain($finding->statement);
});

it('constrains job_analysis_finding_id, career_fact_key, and education_id to the exact supplied sets', function () {
    $schema = (new JobMatchPromptV1)->jsonSchema([101, 102], ['fact-a', 'fact-b'], [7]);

    $findingIdSchema = $schema['properties']['findings']['items']['properties']['job_analysis_finding_id'];
    $factKeySchema = $schema['properties']['findings']['items']['properties']['matches']['items']['properties']['career_fact_key'];
    $educationIdSchema = $schema['properties']['findings']['items']['properties']['education_matches']['items']['properties']['education_id'];

    expect($findingIdSchema['enum'])->toBe([101, 102])
        ->and($factKeySchema['enum'])->toBe(['fact-a', 'fact-b'])
        ->and($educationIdSchema['enum'])->toBe([7]);
});

it('substitutes a sentinel enum value for education_id when the profile has no Education rows, rather than emitting an empty enum', function () {
    $schema = (new JobMatchPromptV1)->jsonSchema([1], ['fact-a'], []);

    $educationIdSchema = $schema['properties']['findings']['items']['properties']['education_matches']['items']['properties']['education_id'];

    expect($educationIdSchema['enum'])->not->toBeEmpty()
        ->and($educationIdSchema['enum'])->not->toContain(1);
});

it('marks additionalProperties false throughout, matching the strict structured-output contract', function () {
    $schema = (new JobMatchPromptV1)->jsonSchema([1], ['a'], [1]);

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['properties']['matches']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['properties']['education_matches']['items']['additionalProperties'])->toBeFalse();
});
