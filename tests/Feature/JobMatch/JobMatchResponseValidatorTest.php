<?php

use App\Exceptions\InvalidJobMatchResponseException;
use App\Support\JobMatch\JobMatchResponseValidator;

function jobMatchValidator(): JobMatchResponseValidator
{
    return new JobMatchResponseValidator;
}

function validJobMatchResponse(): array
{
    return [
        'findings' => [
            [
                'job_analysis_finding_id' => 1,
                'coverage' => 'supported',
                'coverage_rationale' => 'test',
                'matches' => [
                    ['career_fact_key' => 'fact-a', 'relationship' => 'direct', 'rationale' => 'x'],
                ],
                'education_matches' => [],
            ],
            [
                'job_analysis_finding_id' => 2,
                'coverage' => 'no_evidence',
                'coverage_rationale' => 'test',
                'matches' => [],
                'education_matches' => [],
            ],
        ],
    ];
}

it('accepts a fully valid response', function () {
    $validated = jobMatchValidator()->validate(validJobMatchResponse(), [1, 2], ['fact-a'], []);

    expect($validated['findings'])->toHaveCount(2);
});

it('accepts not_assessable with zero support references', function () {
    $response = validJobMatchResponse();
    $response['findings'][1]['coverage'] = 'not_assessable';

    $validated = jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);

    expect($validated['findings'][1]['coverage'])->toBe('not_assessable');
});

it('accepts partial coverage with at least one CareerFact support reference', function () {
    $response = validJobMatchResponse();
    $response['findings'][1]['coverage'] = 'partial';
    $response['findings'][1]['matches'] = [['career_fact_key' => 'fact-a', 'relationship' => 'transferable', 'rationale' => 'x']];

    $validated = jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);

    expect($validated['findings'][1]['coverage'])->toBe('partial');
});

it('accepts partial coverage with at least one Education support reference', function () {
    $response = validJobMatchResponse();
    $response['findings'][1]['coverage'] = 'partial';
    $response['findings'][1]['education_matches'] = [['education_id' => 1, 'relationship' => 'transferable', 'rationale' => 'x']];

    $validated = jobMatchValidator()->validate($response, [1, 2], ['fact-a'], [1]);

    expect($validated['findings'][1]['coverage'])->toBe('partial');
});

it('rejects a response missing a supplied finding', function () {
    $response = validJobMatchResponse();
    unset($response['findings'][1]);
    $response['findings'] = array_values($response['findings']);

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects a response with a duplicate finding id', function () {
    $response = validJobMatchResponse();
    $response['findings'][] = $response['findings'][0];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects a response referencing a finding id not supplied', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['job_analysis_finding_id'] = 999;

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects a hallucinated CareerFact key not supplied in the input', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['matches'][0]['career_fact_key'] = 'never-supplied-key';

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects a hallucinated Education id not supplied in the input', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['education_matches'] = [
        ['education_id' => 999, 'relationship' => 'direct', 'rationale' => 'x'],
    ];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], [1]);
})->throws(InvalidJobMatchResponseException::class);

it('rejects a CareerFact key that belongs to a different CareerProfile (cross-profile reference)', function () {
    // Simulates a response citing a real, existing key that simply
    // wasn't part of THIS run's supplied set — e.g. it belongs to a
    // different profile entirely.
    $response = validJobMatchResponse();
    $response['findings'][0]['matches'][0]['career_fact_key'] = 'another-profiles-fact-key';

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects a duplicate CareerFact reference within one finding', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['matches'][] = ['career_fact_key' => 'fact-a', 'relationship' => 'contextual', 'rationale' => 'y'];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects a duplicate Education reference within one finding', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['education_matches'] = [
        ['education_id' => 1, 'relationship' => 'direct', 'rationale' => 'x'],
        ['education_id' => 1, 'relationship' => 'contextual', 'rationale' => 'y'],
    ];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], [1]);
})->throws(InvalidJobMatchResponseException::class);

it('rejects no_evidence with a non-empty CareerFact match list', function () {
    $response = validJobMatchResponse();
    $response['findings'][1]['matches'] = [['career_fact_key' => 'fact-a', 'relationship' => 'direct', 'rationale' => 'x']];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects no_evidence with a non-empty Education match list', function () {
    $response = validJobMatchResponse();
    $response['findings'][1]['education_matches'] = [['education_id' => 1, 'relationship' => 'direct', 'rationale' => 'x']];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], [1]);
})->throws(InvalidJobMatchResponseException::class);

it('rejects not_assessable with a non-empty CareerFact match list', function () {
    $response = validJobMatchResponse();
    $response['findings'][1]['coverage'] = 'not_assessable';
    $response['findings'][1]['matches'] = [['career_fact_key' => 'fact-a', 'relationship' => 'direct', 'rationale' => 'x']];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects not_assessable with a non-empty Education match list', function () {
    $response = validJobMatchResponse();
    $response['findings'][1]['coverage'] = 'not_assessable';
    $response['findings'][1]['education_matches'] = [['education_id' => 1, 'relationship' => 'direct', 'rationale' => 'x']];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], [1]);
})->throws(InvalidJobMatchResponseException::class);

it('rejects supported with zero support references', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['matches'] = [];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects partial with zero support references', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['coverage'] = 'partial';
    $response['findings'][0]['matches'] = [];

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects an unsupported coverage enum value', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['coverage'] = 'definitely_qualified';

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects an unsupported relationship enum value', function () {
    $response = validJobMatchResponse();
    $response['findings'][0]['matches'][0]['relationship'] = 'perfect';

    jobMatchValidator()->validate($response, [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);

it('rejects a response missing the findings key entirely', function () {
    jobMatchValidator()->validate([], [1, 2], ['fact-a'], []);
})->throws(InvalidJobMatchResponseException::class);
