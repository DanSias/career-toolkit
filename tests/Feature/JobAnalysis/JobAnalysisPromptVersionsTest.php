<?php

use App\Models\JobPosting;
use App\Support\JobAnalysis\JobAnalysisResponseValidator;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV1;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV2;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV3;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV4;

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

/**
 * Confirms JobAnalysisPromptV2 is completely untouched by the V3
 * evidence-discipline revision — `job-analysis-v2` has already been
 * persisted (a real JobAnalysis row exists with that prompt_version),
 * so V2's behavior must remain exactly as it was.
 */
it('keeps JobAnalysisPromptV2 historically unchanged after the V3 revision', function () {
    $prompt = new JobAnalysisPromptV2;

    expect($prompt->version())->toBe('job-analysis-v2')
        ->and($prompt->schemaVersion())->toBe('1.0')
        ->and($prompt->jsonSchema()['properties']['findings']['items']['properties']['evidence'])
        ->not->toHaveKey('maxItems')
        ->and($prompt->systemPrompt())
        ->toContain('occurrence as its own entry in that finding\'s evidence list');
});

/**
 * Confirms the V2 -> V3 revision: only the evidence policy and
 * role_summary/notes concision guidance change. See
 * JobAnalysisPromptV3's docblock for the full rationale.
 */
it('versions JobAnalysisPromptV3 correctly while keeping the same schema_version as V2', function () {
    $prompt = new JobAnalysisPromptV3;

    expect($prompt->version())->toBe('job-analysis-v3')
        ->and($prompt->schemaVersion())->toBe('1.0');
});

it('is identical to V2\'s JSON schema except evidence.maxItems, sourced from the shared validator constant', function () {
    $v2Schema = (new JobAnalysisPromptV2)->jsonSchema();
    $v3Schema = (new JobAnalysisPromptV3)->jsonSchema();

    $v2Evidence = &$v2Schema['properties']['findings']['items']['properties']['evidence'];
    $v3Evidence = &$v3Schema['properties']['findings']['items']['properties']['evidence'];

    expect($v3Evidence['maxItems'])->toBe(JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING)
        ->and($v2Evidence)->not->toHaveKey('maxItems');

    // Once the one expected difference is normalized away, the schemas
    // must be equal (toEqual, not toBe — key insertion order legitimately
    // differs between the two literal array definitions).
    $v2Evidence['maxItems'] = JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING;
    expect($v3Schema)->toEqual($v2Schema);
});

it('does not impose any maxItems on findings under V3', function () {
    $schema = (new JobAnalysisPromptV3)->jsonSchema();

    expect($schema['properties']['findings'])
        ->toHaveKey('minItems', 1)
        ->not->toHaveKey('maxItems');
});

it('keeps evidence minItems at 1 under V3', function () {
    $schema = (new JobAnalysisPromptV3)->jsonSchema();

    expect($schema['properties']['findings']['items']['properties']['evidence']['minItems'])->toBe(1);
});

it('reverses the V2 one-entry-per-repeated-occurrence evidence instruction under V3', function () {
    $systemPrompt = (new JobAnalysisPromptV3)->systemPrompt();

    expect($systemPrompt)
        ->not->toContain('occurrence as its own entry in that finding\'s evidence list')
        ->toContain('do NOT add a separate')
        ->toContain('evidence entry for every occurrence')
        ->toContain('at most 2 evidence entries');
});

it('adds concision guidance for role_summary and notes under V3', function () {
    $systemPrompt = (new JobAnalysisPromptV3)->systemPrompt();

    expect($systemPrompt)
        ->toContain('a concise role_summary (2-4 sentences')
        ->toContain('Keep it to one short sentence when used');
});

it('sends v3 only company, title, location, and description, same as v1/v2', function () {
    $job = new JobPosting([
        'company' => 'Acme Corp',
        'title' => 'Staff Engineer',
        'location' => 'Remote',
        'description' => 'Build backend services and ship production features.',
    ]);

    $userPrompt = (new JobAnalysisPromptV3)->userPrompt($job);

    expect($userPrompt)
        ->toContain('Acme Corp')
        ->toContain('Staff Engineer')
        ->toContain('Remote')
        ->toContain('Build backend services and ship production features.')
        ->not->toContain('CareerFact')
        ->not->toContain('candidate');
});

/**
 * Confirms the V3 -> V4 revision: only the evidence contract changes,
 * from model-generated verbatim excerpts to deterministic source
 * segment references. See JobAnalysisPromptV4's docblock for the full
 * rationale (three real TRM Labs generations discarded over
 * copy-fidelity noise despite substantively verbatim evidence).
 */
it('versions JobAnalysisPromptV4 correctly with a real schema_version bump from V3', function () {
    $prompt = new JobAnalysisPromptV4;

    expect($prompt->version())->toBe('job-analysis-v4')
        ->and($prompt->schemaVersion())->toBe('2.0');
});

it('no longer asks the model to reproduce evidence text verbatim under V4', function () {
    $systemPrompt = (new JobAnalysisPromptV4)->systemPrompt();

    expect($systemPrompt)
        ->not->toContain('Copy excerpts exactly as they appear')
        ->not->toContain('character-for-character substring')
        ->toContain('evidence_refs')
        ->toContain('cite which segment');
});

it('uses evidence_refs, not evidence, in the V4 JSON schema', function () {
    $schema = (new JobAnalysisPromptV4)->jsonSchema(['S001', 'S002']);
    $findingProperties = $schema['properties']['findings']['items']['properties'];

    expect($findingProperties)->toHaveKey('evidence_refs')
        ->and($findingProperties)->not->toHaveKey('evidence')
        ->and($schema['properties']['findings']['items']['required'])->toContain('evidence_refs')
        ->and($schema['properties']['findings']['items']['required'])->not->toContain('evidence');
});

it('keeps evidence_refs minItems at 1 under V4', function () {
    $schema = (new JobAnalysisPromptV4)->jsonSchema(['S001']);

    expect($schema['properties']['findings']['items']['properties']['evidence_refs']['minItems'])->toBe(1);
});

it('caps evidence_refs maxItems at MAX_EVIDENCE_PER_FINDING under V4', function () {
    $schema = (new JobAnalysisPromptV4)->jsonSchema(['S001']);

    expect($schema['properties']['findings']['items']['properties']['evidence_refs']['maxItems'])
        ->toBe(JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING);
});

it('constrains evidence_refs items to exactly the supplied segment id set', function () {
    $schema = (new JobAnalysisPromptV4)->jsonSchema(['S001', 'S002', 'S003']);

    expect($schema['properties']['findings']['items']['properties']['evidence_refs']['items']['enum'])
        ->toBe(['S001', 'S002', 'S003']);
});

it('does not impose any maxItems on findings under V4', function () {
    $schema = (new JobAnalysisPromptV4)->jsonSchema(['S001']);

    expect($schema['properties']['findings'])
        ->toHaveKey('minItems', 1)
        ->not->toHaveKey('maxItems');
});

it('presents the job description as labeled source segments in the V4 user prompt', function () {
    $job = new JobPosting([
        'company' => 'Acme Corp',
        'title' => 'Staff Engineer',
        'location' => 'Remote',
        'description' => 'irrelevant — segments are passed in directly',
    ]);

    $userPrompt = (new JobAnalysisPromptV4)->userPrompt($job, ['S001' => 'Build backend services.', 'S002' => 'Ship production features.']);

    expect($userPrompt)
        ->toContain('Acme Corp')
        ->toContain('Staff Engineer')
        ->toContain('Remote')
        ->toContain('[S001] Build backend services.')
        ->toContain('[S002] Ship production features.')
        ->not->toContain('CareerFact')
        ->not->toContain('candidate');
});

it('keeps JobAnalysisPromptV3 historically unchanged after the V4 revision', function () {
    $prompt = new JobAnalysisPromptV3;

    expect($prompt->version())->toBe('job-analysis-v3')
        ->and($prompt->schemaVersion())->toBe('1.0')
        ->and($prompt->jsonSchema()['properties']['findings']['items']['properties'])
        ->toHaveKey('evidence')
        ->and($prompt->jsonSchema()['properties']['findings']['items']['properties'])
        ->not->toHaveKey('evidence_refs');
});
