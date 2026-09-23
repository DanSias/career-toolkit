<?php

use App\Support\JobMatch\CandidatePayloadBuilder;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\JobMatch\Prompts\JobMatchPromptV2;
use App\Support\JobMatch\Prompts\JobMatchPromptV3;
use Tests\Support\JobMatchFixtures;

it('versions independently of JobAnalysis, JobMatchPromptV1, and JobMatchPromptV2', function () {
    $prompt = new JobMatchPromptV3;

    expect($prompt->version())->toBe('job-match-v3')
        ->and($prompt->schemaVersion())->toBe('1.0');
});

it('embeds the candidate and job payloads in the user prompt, identically to V2', function () {
    ['profile' => $profile, 'factOne' => $fact] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $finding] = JobMatchFixtures::job();

    $candidatePayload = (new CandidatePayloadBuilder)->build($profile);
    $jobPayload = (new JobPayloadBuilder)->build($analysis);

    $userPrompt = (new JobMatchPromptV3)->userPrompt($candidatePayload, $jobPayload);

    expect($userPrompt)
        ->toContain($fact->key)
        ->toContain($fact->statement)
        ->toContain($finding->statement);
});

it('produces a JSON schema byte-for-byte identical to JobMatchPromptV2 for the same inputs — V3 is prompt-only', function () {
    $v2Schema = (new JobMatchPromptV2)->jsonSchema([101, 102], ['fact-a', 'fact-b'], [7]);
    $v3Schema = (new JobMatchPromptV3)->jsonSchema([101, 102], ['fact-a', 'fact-b'], [7]);

    expect($v3Schema)->toBe($v2Schema);
});

it('retains V2\'s no_evidence clarification word for word', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain("contains no\n  evidence at all addressing this finding")
        ->and($systemPrompt)->toContain("If you find yourself\n  citing any `career_fact_key` or `education_id` for a finding,\n  that finding is not `no_evidence`")
        ->and($systemPrompt)->not->toContain('meaningful evidence addressing this finding');
});

it('explicitly states that attached Skills are legitimate fact-local association evidence', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain("legitimate evidence that a\ntechnology, platform, capability, or practice is associated with\nthat specific CareerFact")
        ->and($systemPrompt)->toContain('treat an attached Skill as real evidence');
});

it('explicitly states that a Skill alone does not establish depth, duration, scale, ownership, or implementation details', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain('not depth of expertise')
        ->and($systemPrompt)->toContain('not years or duration of use, not production scale, not personal')
        ->and($systemPrompt)->toContain('implementation or ownership')
        ->and($systemPrompt)->toContain('not extensive hands-on coding in it, not')
        ->and($systemPrompt)->toContain('architecture ownership');
});

it('states that each CareerFact is its own evidence boundary', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain('Each CareerFact is its own evidence boundary.');
});

it('prohibits importing a detail from a different CareerFact into a citation\'s rationale', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain("Never import a technology, metric, implementation detail,\nownership claim, or other detail from a different CareerFact into")
        ->and($systemPrompt)->toContain('even when both facts share the same')
        ->and($systemPrompt)->toContain('employer, the same role, or closely related projects');
});

it('anchors direct relationship to the finding\'s literal statement, not a broad category', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain("the finding's statement literally names")
        ->and($systemPrompt)->toContain('is not `direct` merely because both belong to the same broad');
});

it('distinguishes an analogous different-technology capability as transferable', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain('demonstrates a closely analogous')
        ->and($systemPrompt)->toContain('different technology, domain, or context');
});

it('distinguishes nearby, non-demonstrative evidence as contextual', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain("without itself\n  demonstrating the requested capability")
        ->and($systemPrompt)->toContain('should not become `direct` merely because both are');
});

it('requires the relationship enum and its rationale to agree', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain('The `relationship` value and its `rationale` must agree.')
        ->and($systemPrompt)->toContain('do not emit');
});

it('leaves coverage semantics unchanged from V2 — coverage is holistic and independent of any single match relationship', function () {
    $systemPrompt = (new JobMatchPromptV3)->systemPrompt();

    expect($systemPrompt)->toContain("A finding's\noverall `coverage` is a holistic judgment across all its matches,\nindependent of any single match's `relationship`")
        ->and($systemPrompt)->toContain('a `partial` finding can have direct')
        ->and($systemPrompt)->toContain('evidence for one part of a multi-part requirement while lacking');
});

it('leaves the not_assessable definition and other untouched sections byte-identical to V2', function () {
    $notAssessablePhrase = 'reconsider whether it\'s actually
  `no_evidence`, `partial`, or `supported` instead.';
    $doNotInventIdentifiers = 'Every `career_fact_key` you use must be one of the exact keys';
    $yearsRule = 'Never state a computed candidate-years figure anywhere, for any';

    foreach ([$notAssessablePhrase, $doNotInventIdentifiers, $yearsRule] as $phrase) {
        expect((new JobMatchPromptV2)->systemPrompt())->toContain($phrase)
            ->and((new JobMatchPromptV3)->systemPrompt())->toContain($phrase);
    }
});

it('marks additionalProperties false throughout, identically to V2', function () {
    $schema = (new JobMatchPromptV3)->jsonSchema([1], ['a'], [1]);

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['properties']['matches']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['findings']['items']['properties']['education_matches']['items']['additionalProperties'])->toBeFalse();
});
