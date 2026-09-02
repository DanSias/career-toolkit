<?php

use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Support\JobAnalysis\JobAnalysisResponseValidator;
use Tests\Support\JobAnalysisFixtures;

// Named jobAnalysisResponseValidator(), not validator() — the latter
// collides with Laravel's own global validator() helper.
function jobAnalysisResponseValidator(): JobAnalysisResponseValidator
{
    return new JobAnalysisResponseValidator;
}

it('accepts a fully valid payload and returns exactly the validated fields', function () {
    $validated = jobAnalysisResponseValidator()->validate(JobAnalysisFixtures::validPayload());

    expect($validated['role_summary'])->toBeString()
        ->and($validated['findings'])->toHaveCount(3);
});

it('rejects a payload missing role_summary', function () {
    $payload = JobAnalysisFixtures::validPayload();
    unset($payload['role_summary']);

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a payload with empty findings', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'] = [];

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a payload missing findings entirely', function () {
    $payload = JobAnalysisFixtures::validPayload();
    unset($payload['findings']);

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects an unsupported category enum value', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['category'] = 'skills';

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects an unsupported overall_seniority enum value', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['overall_seniority'] = 'entry_level';

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a negative years_experience_min', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['years_experience_min'] = -1;

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects years_experience_max less than years_experience_min', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['years_experience_min'] = 5;
    $payload['findings'][0]['years_experience_max'] = 3;

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);

it('accepts a null requirement_strength', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['requirement_strength'] = null;

    $validated = jobAnalysisResponseValidator()->validate($payload);

    expect($validated['findings'][0]['requirement_strength'])->toBeNull();
});

it('rejects a finding with no evidence', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence'] = [];

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects evidence missing an excerpt', function () {
    $payload = JobAnalysisFixtures::validPayload();
    unset($payload['findings'][0]['evidence'][0]['excerpt']);

    jobAnalysisResponseValidator()->validate($payload);
})->throws(InvalidJobAnalysisResponseException::class);
