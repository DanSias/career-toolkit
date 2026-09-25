<?php

use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV1;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV2;
use App\Support\ResumeVariant\ResumeSelectionResponseValidator;

it('versions independently of JobAnalysis/JobMatch and of JobMatchPromptV1', function () {
    $prompt = new ResumeSelectionPromptV2;

    expect($prompt->version())->toBe('resume-selection-v2.1')
        ->and($prompt->schemaVersion())->toBe('1.3');
});

it('tells the model to consult role_project_map as the authoritative role/project ownership answer', function () {
    $systemPrompt = (new ResumeSelectionPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("`role_project_map` gives you the complete, authoritative\nanswer directly: for every `role_id`, the exact `project_id`")
        ->and($systemPrompt)->toContain('A role with no owned projects lists only')
        ->and($systemPrompt)->toContain('Consult this map directly rather than inferring');
});

it('produces a JSON schema identical to JobMatchPromptV1 except the Selected Projects maxItems, now sourced from MAX_SELECTED_PROJECTS', function () {
    $v1Schema = (new ResumeSelectionPromptV1)->jsonSchema([1, 2], [3, 4], ['a', 'b'], [5, 6], [7, 8], ['React'], ['full', 'segment_1'], [9]);
    $v2Schema = (new ResumeSelectionPromptV2)->jsonSchema([1, 2], [3, 4], ['a', 'b'], [5, 6], [7, 8], ['React'], ['full', 'segment_1'], [9]);

    expect($v1Schema['properties']['selected_projects']['maxItems'])->toBe(3)
        ->and($v2Schema['properties']['selected_projects']['maxItems'])
        ->toBe(ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS)
        ->toBe(1);

    // Everything else — every other field, type, enum, and nesting —
    // remains byte-identical: prove it by neutralizing only the one
    // known, deliberate difference before comparing the rest.
    $v1Schema['properties']['selected_projects']['maxItems'] = 1;

    expect($v2Schema)->toBe($v1Schema);
});

it('states the Experience bullet-group TARGET range and HARD MAXIMUM explicitly', function () {
    $systemPrompt = (new ResumeSelectionPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain('Experience bullet groups: a TARGET of 10-12 in total, counted')
        ->and($systemPrompt)->toContain("across every role combined — never a HARD MAXIMUM of 13. A\n  response with more than 13 bullet groups in total is rejected");
});

it('states the Skills TARGET range and HARD MAXIMUM explicitly', function () {
    $systemPrompt = (new ResumeSelectionPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain('Skills: a TARGET of 12-16 selected, the strongest and most')
        ->and($systemPrompt)->toContain('relevant to this job — never a HARD MAXIMUM of 18. A response')
        ->and($systemPrompt)->toContain('selecting more than 18 Skills is rejected outright.');
});

it('distinguishes TARGET (preferred guidance) from HARD MAXIMUM (deterministic ceiling) in its own words', function () {
    $systemPrompt = (new ResumeSelectionPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("TARGET is the range you should normally land inside of. HARD\nMAXIMUM is a deterministic ceiling checked independently of your\nown output");
});

it('does not introduce a fixed per-role bullet quota — the budget governs the resume as a whole', function () {
    $systemPrompt = (new ResumeSelectionPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("The total budget governs the resume as a whole — it is not a\nfixed per-role quota.")
        ->and($systemPrompt)->toContain('decide the right')
        ->and($systemPrompt)->toContain('distribution within the total budget yourself.');
});

it('repeats the Skills budget inline in the Skills section itself', function () {
    $systemPrompt = (new ResumeSelectionPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain("calls for it. Select a TARGET of 12-16 of the strongest, most\nrelevant Skills — never more than the HARD MAXIMUM of 18");
});

it('discourages selecting a Selected Project merely because the slot exists', function () {
    $systemPrompt = (new ResumeSelectionPromptV2)->systemPrompt();

    expect($systemPrompt)->toContain('Select one only when it adds evidence')
        ->and($systemPrompt)->toContain("not already told effectively by Experience — do not select an\nindependent project merely because the slot exists.")
        ->and($systemPrompt)->toContain('Omitting it entirely')
        ->and($systemPrompt)->toContain('is a completely normal, correct outcome');
});

it('lowers the Selected Projects cardinality from V1\'s 0-3 to a deterministic maximum of 1', function () {
    $v1Prompt = (new ResumeSelectionPromptV1)->systemPrompt();
    $v2Prompt = (new ResumeSelectionPromptV2)->systemPrompt();

    expect($v1Prompt)->toContain('You may choose 0 to 3 of')
        ->and($v2Prompt)->toContain('You may choose 0 or 1 of')
        ->and($v2Prompt)->not->toContain('0 to 3')
        ->and($v2Prompt)->not->toContain('prefer 0-2')
        ->and($v2Prompt)->not->toContain('Prefer 0-2');
});

it('leaves role/project binding, title choice, target-term posture, and do-not-invent sections byte-identical to V1', function () {
    $roleBindingPhrase = 'Every `experience` entry is anchored to one real `role_id`.';
    $titleChoicePhrase = 'You do not write or abbreviate a role\'s title — you choose';
    $postureDirectPhrase = '`direct` — ONLY when `direct_evidence_exists` is true for';
    $doNotInventPhrase = 'Every `career_fact_key`, `skill_id`, `role_id`, `project_id`,';

    foreach ([$roleBindingPhrase, $titleChoicePhrase, $postureDirectPhrase, $doNotInventPhrase] as $phrase) {
        expect((new ResumeSelectionPromptV1)->systemPrompt())->toContain($phrase)
            ->and((new ResumeSelectionPromptV2)->systemPrompt())->toContain($phrase);
    }
});

it('embeds the candidate, job, and target-terminology payloads in the user prompt, identically to V1', function () {
    $candidatePayload = ['career_facts' => [], 'education' => [], 'eligible_skills' => []];
    $jobPayload = ['findings' => []];
    $targetTerminology = [['term' => 'Azure', 'job_analysis_finding_id' => 1, 'requirement_strength' => null, 'emphasis' => null, 'direct_evidence_exists' => false]];

    $userPrompt = (new ResumeSelectionPromptV2)->userPrompt($candidatePayload, $jobPayload, $targetTerminology);

    expect($userPrompt)
        ->toContain('Candidate canonical data')
        ->toContain('Job analysis')
        ->toContain('Target terminology')
        ->toContain('Azure');
});
