<?php

use App\Support\JobMatch\CandidatePayloadBuilder;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\JobMatch\Prompts\JobMatchPromptV1;
use App\Support\JobMatch\Prompts\JobMatchPromptV2;
use Tests\Support\JobMatchFixtures;

it('versions independently of JobAnalysis and of JobMatchPromptV1', function () {
    $prompt = new JobMatchPromptV2;

    expect($prompt->version())->toBe('job-match-v2')
        ->and($prompt->schemaVersion())->toBe('1.0');
});

it('retains its original relationship wording and no Skills guidance, unmodified by JobMatchPromptV3\'s additions', function () {
    $systemPrompt = (new JobMatchPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain('the evidence demonstrates substantially the same')
        ->and($systemPrompt)->not->toContain('Each CareerFact is its own evidence boundary')
        ->and($systemPrompt)->not->toContain('the finding\'s statement literally names')
        ->and($systemPrompt)->not->toContain('The `relationship` value and its `rationale` must agree')
        ->and($systemPrompt)->not->toContain('attached `skills`');
});

it('embeds the candidate and job payloads in the user prompt, identically to V1', function () {
    ['profile' => $profile, 'factOne' => $fact] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $finding] = JobMatchFixtures::job();

    $candidatePayload = (new CandidatePayloadBuilder)->build($profile);
    $jobPayload = (new JobPayloadBuilder)->build($analysis);

    $userPrompt = (new JobMatchPromptV2)->userPrompt($candidatePayload, $jobPayload);

    expect($userPrompt)
        ->toContain($fact->key)
        ->toContain($fact->statement)
        ->toContain($finding->statement);
});

it('constrains job_analysis_finding_id, career_fact_key, and education_id to the exact supplied sets, identically to V1', function () {
    $schema = (new JobMatchPromptV2)->jsonSchema([101, 102], ['fact-a', 'fact-b'], [7]);

    $findingIdSchema = $schema['properties']['findings']['items']['properties']['job_analysis_finding_id'];
    $factKeySchema = $schema['properties']['findings']['items']['properties']['matches']['items']['properties']['career_fact_key'];
    $educationIdSchema = $schema['properties']['findings']['items']['properties']['education_matches']['items']['properties']['education_id'];

    expect($findingIdSchema['enum'])->toBe([101, 102])
        ->and($factKeySchema['enum'])->toBe(['fact-a', 'fact-b'])
        ->and($educationIdSchema['enum'])->toBe([7]);
});

it('substitutes a sentinel enum value for education_id when the profile has no Education rows, identically to V1', function () {
    $schema = (new JobMatchPromptV2)->jsonSchema([1], ['fact-a'], []);

    $educationIdSchema = $schema['properties']['findings']['items']['properties']['education_matches']['items']['properties']['education_id'];

    expect($educationIdSchema['enum'])->not->toBeEmpty()
        ->and($educationIdSchema['enum'])->not->toContain(1);
});

it('marks additionalProperties false throughout, identically to V1', function () {
    $schema = (new JobMatchPromptV2)->jsonSchema([1], ['a'], [1]);

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['properties']['matches']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['properties']['education_matches']['items']['additionalProperties'])->toBeFalse();
});

it('produces a JSON schema byte-for-byte identical to JobMatchPromptV1 for the same inputs — the clarification is prompt-only', function () {
    $v1Schema = (new JobMatchPromptV1)->jsonSchema([101, 102], ['fact-a', 'fact-b'], [7]);
    $v2Schema = (new JobMatchPromptV2)->jsonSchema([101, 102], ['fact-a', 'fact-b'], [7]);

    expect($v2Schema)->toBe($v1Schema);
});

it('communicates that no_evidence means no evidence at all, dropping V1\'s ambiguous "meaningful evidence" wording', function () {
    $systemPrompt = (new JobMatchPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("contains no\n  evidence at all addressing this finding")
        ->and($systemPrompt)->not->toContain('meaningful evidence addressing this finding');
});

it('communicates that citing any career_fact_key or education_id makes no_evidence impossible for that finding', function () {
    $systemPrompt = (new JobMatchPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("If you find yourself\n  citing any `career_fact_key` or `education_id` for a finding,\n  that finding is not `no_evidence`");
});

it('communicates that relevant-but-insufficient evidence is partial, cited accordingly, never no_evidence', function () {
    $systemPrompt = (new JobMatchPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("relevant-but-insufficient\n  evidence")
        ->and($systemPrompt)->toContain("is `partial`,\n  cited accordingly, never `no_evidence`");
});

it('gives personal-rather-than-professional evidence as an explicit example belonging under partial, not no_evidence', function () {
    $systemPrompt = (new JobMatchPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("personal\n  rather than professional");
});

it('gives partial coverage of a multi-part requirement as an explicit example belonging under partial, not no_evidence', function () {
    $systemPrompt = (new JobMatchPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("covering only part of a multi-part\n  requirement");
});

it('leaves the partial coverage definition itself unchanged from V1', function () {
    $partialPhrase = "relevant evidence exists but doesn't fully cover what\n  the finding asks for";

    expect((new JobMatchPromptV1)->systemPrompt())->toContain($partialPhrase)
        ->and((new JobMatchPromptV2)->systemPrompt())->toContain($partialPhrase);
});

it('leaves the not_assessable definition and every other section unchanged from V1', function () {
    $notAssessablePhrase = 'reconsider whether it\'s actually
  `no_evidence`, `partial`, or `supported` instead.';
    $relationshipPhrase = 'Each `matches`/`education_matches` entry has a `relationship`.';
    $doNotInventPhrase = 'Every `career_fact_key` you use must be one of the exact keys';

    foreach ([$notAssessablePhrase, $relationshipPhrase, $doNotInventPhrase] as $phrase) {
        expect((new JobMatchPromptV1)->systemPrompt())->toContain($phrase)
            ->and((new JobMatchPromptV2)->systemPrompt())->toContain($phrase);
    }
});
