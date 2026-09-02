<?php

use App\Models\JobPosting;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV1;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV2;

/**
 * Confirms the V1 -> V2 revision is exactly what it claims to be: a
 * prompt-semantics-only change. Deliberately does not assert on prompt
 * prose content — only on version identifiers and the one thing that
 * must NOT have changed, the JSON schema.
 */
it('keeps JobAnalysisPromptV1 historically unchanged', function () {
    $prompt = new JobAnalysisPromptV1;

    expect($prompt->version())->toBe('job-analysis-v1')
        ->and($prompt->schemaVersion())->toBe('1.0');
});

it('versions JobAnalysisPromptV2 correctly while keeping the same schema_version', function () {
    $prompt = new JobAnalysisPromptV2;

    expect($prompt->version())->toBe('job-analysis-v2')
        ->and($prompt->schemaVersion())->toBe('1.0');
});

it('does not change the structured-output JSON schema between v1 and v2', function () {
    $v1 = new JobAnalysisPromptV1;
    $v2 = new JobAnalysisPromptV2;

    expect($v2->jsonSchema())->toBe($v1->jsonSchema());
});

it('sends v2 only company, title, location, and description, same as v1', function () {
    $job = new JobPosting([
        'company' => 'Acme Corp',
        'title' => 'Staff Engineer',
        'location' => 'Remote',
        'description' => 'Build backend services and ship production features.',
    ]);

    $userPrompt = (new JobAnalysisPromptV2)->userPrompt($job);

    expect($userPrompt)
        ->toContain('Acme Corp')
        ->toContain('Staff Engineer')
        ->toContain('Remote')
        ->toContain('Build backend services and ship production features.')
        ->not->toContain('CareerFact')
        ->not->toContain('candidate');
});
