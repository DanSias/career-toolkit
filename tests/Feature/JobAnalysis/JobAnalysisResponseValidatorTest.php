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
    $validated = jobAnalysisResponseValidator()->validate(JobAnalysisFixtures::validPayload(), JobAnalysisFixtures::segmentIds());

    expect($validated['role_summary'])->toBeString()
        ->and($validated['findings'])->toHaveCount(3);
});

it('rejects a payload missing role_summary', function () {
    $payload = JobAnalysisFixtures::validPayload();
    unset($payload['role_summary']);

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a payload with empty findings', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'] = [];

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a payload missing findings entirely', function () {
    $payload = JobAnalysisFixtures::validPayload();
    unset($payload['findings']);

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects an unsupported category enum value', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['category'] = 'skills';

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects an unsupported overall_seniority enum value', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['overall_seniority'] = 'entry_level';

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a negative years_experience_min', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['years_experience_min'] = -1;

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects years_experience_max less than years_experience_min', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['years_experience_min'] = 5;
    $payload['findings'][0]['years_experience_max'] = 3;

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('accepts a null requirement_strength', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['requirement_strength'] = null;

    $validated = jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());

    expect($validated['findings'][0]['requirement_strength'])->toBeNull();
});

it('rejects a finding with no evidence_refs', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = [];

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

/**
 * V4 evidence-reference validation — see
 * docs/job-analysis-generation.md "Async Job Analysis" (evidence
 * architecture). JobAnalysisResponseValidator is the sole authoritative
 * check that a model never invents a segment id; the schema's own
 * `enum` constraint is advisory/provider-level protection only.
 */
it('accepts one valid evidence_refs entry', function () {
    $payload = JobAnalysisFixtures::validPayload();

    $validated = jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());

    expect($validated['findings'][0]['evidence_refs'])->toBe(['S003']);
});

it('accepts two valid evidence_refs entries', function () {
    $payload = JobAnalysisFixtures::validPayload();

    $validated = jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());

    expect($validated['findings'][1]['evidence_refs'])->toBe(['S004', 'S005']);
});

it('rejects a nonexistent evidence_refs id', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = ['S999'];

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a malformed evidence_refs value', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = ['not-a-segment-id'];

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a non-string evidence_refs entry', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = [3];

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('exposes a single shared MAX_EVIDENCE_PER_FINDING constant equal to 2', function () {
    expect(JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING)->toBe(2);
});

it('accepts exactly one evidence_refs entry', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = ['S003'];

    $validated = jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());

    expect($validated['findings'][0]['evidence_refs'])->toHaveCount(1);
});

it('accepts exactly MAX_EVIDENCE_PER_FINDING (2) evidence_refs entries', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = ['S003', 'S004'];

    $validated = jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());

    expect($validated['findings'][0]['evidence_refs'])->toHaveCount(JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING);
});

it('rejects a finding with more than MAX_EVIDENCE_PER_FINDING (2) evidence_refs entries', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence_refs'] = ['S003', 'S004', 'S005'];

    jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());
})->throws(InvalidJobAnalysisResponseException::class);

it('does not impose any maximum on the number of findings', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $extraFinding = $payload['findings'][0];
    $payload['findings'] = array_merge($payload['findings'], array_fill(0, 50, $extraFinding));

    $validated = jobAnalysisResponseValidator()->validate($payload, JobAnalysisFixtures::segmentIds());

    expect($validated['findings'])->toHaveCount(53);
});
