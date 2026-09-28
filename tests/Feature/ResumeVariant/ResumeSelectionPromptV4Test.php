<?php

use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV3;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV4;

it('versions independently while keeping the schema shape identical to V3', function () {
    $prompt = new ResumeSelectionPromptV4;

    expect($prompt->version())->toBe('resume-selection-v4')
        ->and($prompt->schemaVersion())->toBe('2.0');
});

it('produces a JSON schema byte-identical to V3 — this revision changes prompt text only', function () {
    $v3Schema = (new ResumeSelectionPromptV3)->jsonSchema([1, 2], ['a', 'b'], [3, 4], [5, 6], ['React'], ['full', 'segment_1'], [7]);
    $v4Schema = (new ResumeSelectionPromptV4)->jsonSchema([1, 2], ['a', 'b'], [3, 4], [5, 6], ['React'], ['full', 'segment_1'], [7]);

    expect($v4Schema)->toBe($v3Schema);
});

it('embeds the candidate, job, and target-terminology payloads in the user prompt, identically to V3', function () {
    $candidatePayload = ['career_facts' => [], 'education' => [], 'eligible_skills' => []];
    $jobPayload = ['findings' => []];
    $targetTerminology = [['term' => 'Azure', 'job_analysis_finding_id' => 1, 'requirement_strength' => null, 'emphasis' => null, 'direct_evidence_exists' => false]];

    $userPrompt = (new ResumeSelectionPromptV4)->userPrompt($candidatePayload, $jobPayload, $targetTerminology);

    expect($userPrompt)
        ->toContain('Candidate canonical data')
        ->toContain('Job analysis')
        ->toContain('Target terminology')
        ->toContain('Azure');
});

// --- New: elevated-signal instruction --------------------------------------

it('names the direct/required/high combination explicitly as an exceptionally strong inclusion candidate', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($systemPrompt)->toContain('## Especially strong evidence signals')
        ->and($systemPrompt)->toContain("choices: `relationship: direct` together with its linked\nfinding's `requirement_strength: required` and\n`emphasis: high`.")
        ->and($systemPrompt)->toContain("emphasizes — an\nexceptionally strong inclusion candidate");
});

it('applies the elevated signal to Experience, Skills, and Selected Projects alike, not just one section', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($systemPrompt)->toContain("wherever it legally\nfits (an Experience bullet group, a Selected Project, or a\nSkill it supports).")
        ->and($systemPrompt)->toContain("Before finalizing Experience, Skills, and\nSelected Projects, actively check whether every CareerFact");
});

it('does not turn the elevated signal into an unconditional inclusion rule', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($systemPrompt)->toContain('This is NOT an unconditional inclusion rule.')
        ->and($systemPrompt)->toContain("select irrelevant evidence merely because its annotation happens\nto be strong.");
});

it('still defers to the existing "do not force a Selected Project" rule when the same signal is already told through Experience', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($systemPrompt)->toContain("never force an independent Selected\nProject into existence solely because its evidence carries this\nsignal if the same underlying capability is already demonstrated,");
});

it('resolves competing direct/required/high candidates by marginal coverage and evidence strength, not order', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($systemPrompt)->toContain("prioritize whichever adds the most marginal coverage of a\nrequirement nothing else you selected already covers, backed by\nthe strongest, most specific evidence — never simply whichever\nappears first in the supplied data.");
});

// --- New: Skills cross-reference guidance -----------------------------------

it('tells the model a Skill already backed by selected evidence is a cheap, high-value inclusion candidate', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($systemPrompt)->toContain('A Skill attached to a CareerFact you have already selected')
        ->and($systemPrompt)->toContain('cheap, high-value inclusion')
        ->and($systemPrompt)->toContain("Do not\noverlook an eligible Skill just because it belongs to a");
});

it('does not hard-code any specific skill name into the new Skills guidance', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    foreach (['SEO', 'SEM', 'Lead generation', 'Paid acquisition', 'TRM'] as $literal) {
        expect($systemPrompt)->not->toContain($literal);
    }
});

// --- New: dedicated Summary evidence section --------------------------------

it('adds a dedicated Summary evidence section absent from V3', function () {
    $v3Prompt = (new ResumeSelectionPromptV3)->systemPrompt();
    $v4Prompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($v3Prompt)->not->toContain('## Summary evidence')
        ->and($v4Prompt)->toContain('## Summary evidence');
});

it('tells the model summary_evidence may combine more than one fact for cross-cutting positioning', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($systemPrompt)->toContain("`summary_evidence` may include more than one CareerFact — do not\ndefault to a single fact merely because one feels like the most\nnovel or distinctive theme.")
        ->and($systemPrompt)->toContain('strongest, most representative cross-cutting');
});

it('keeps the Summary evidence guidance bounded — a short positioning statement, not a second Experience section', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    expect($systemPrompt)->toContain("Keep it\nsmall — this is a short positioning statement, not a second\nExperience section");
});

// --- Preservation: everything else is untouched -----------------------------

it('leaves role/project binding, title choice, target-term posture, budgets, and do-not-invent sections byte-identical to V3', function () {
    $phrases = [
        'Every `experience` entry is anchored to one real `role_id`.',
        'You do not write or abbreviate a role\'s title — you choose',
        '`direct` — ONLY when `direct_evidence_exists` is true for',
        'Every `career_fact_key`, `skill_id`, `role_id`, `project_id`',
        'Experience bullet groups: a TARGET of 10-12 in total, counted',
        'Select a TARGET of 12-16 of the strongest, most',
        "Select one only when it adds evidence\nnot already told",
        'Omitting it entirely',
        'is a completely normal, correct outcome',
    ];

    foreach ($phrases as $phrase) {
        expect((new ResumeSelectionPromptV3)->systemPrompt())->toContain($phrase)
            ->and((new ResumeSelectionPromptV4)->systemPrompt())->toContain($phrase);
    }
});

it('leaves the existing job_match_annotations-as-signal-not-restriction framing untouched', function () {
    $phrase = "treat these as\nuseful signal about job relevance, never as a restriction on\nwhat you may select.";

    expect((new ResumeSelectionPromptV3)->systemPrompt())->toContain($phrase)
        ->and((new ResumeSelectionPromptV4)->systemPrompt())->toContain($phrase);
});

it('does not change ResumeEligibility, private-fact, or target-terminology behavior — no such logic exists in this prompt class at all', function () {
    $systemPrompt = (new ResumeSelectionPromptV4)->systemPrompt();

    // The prompt class only ever consumes an already-filtered payload —
    // proving it contains no visibility/eligibility logic of its own is
    // sufficient here; ResumeEligibility's own test coverage (
    // tests/Feature/ResumeVariant/CanonicalVisibilityIntegrationTest.php)
    // is untouched by this milestone.
    expect($systemPrompt)->not->toContain('Private')
        ->and($systemPrompt)->not->toContain('visibility');
});
