<?php

use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV1;

/**
 * Guards the wording-density prompt update made after visual review of
 * the redesigned resume renderer found prose density — not layout —
 * to be the dominant readability problem, and found many consecutive
 * Experience bullets within a Role opening with "Built". Adds explicit
 * bullet-length/focus, verb-variation, and Summary-positioning
 * guidance without touching any existing truthfulness/guardrail rule.
 *
 * v1.3 narrows three behaviors observed across multiple live Pearly
 * generations under v1.2 — see the "checklist synthesis, stronger verb
 * variation, Summary ownership scope" section below for what changed
 * and why.
 *
 * Assertions are made against whitespace-normalized text (the raw
 * heredoc wraps mid-sentence) so they stay robust to rewording that
 * doesn't change the wrap points. Mirrors
 * ResumeSelectionPromptV1Test.php's own pattern.
 */
function normalizedResumeWordingSystemPrompt(): string
{
    return preg_replace('/\s+/', ' ', (new ResumeWordingPromptV1)->systemPrompt());
}

it('bumps the prompt version after this wording-density update', function () {
    expect((new ResumeWordingPromptV1)->version())->toBe('resume-wording-v1.3');
});

it('does not change the schema version — no schema field changed, only prompt guidance', function () {
    expect((new ResumeWordingPromptV1)->schemaVersion())->toBe('1.1');
});

it('states the Experience bullet word-count target, soft maximum, and exception allowance', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('Target approximately 20-28 words per Experience bullet')
        ->toContain('soft maximum is 32 words')
        ->toContain('approximately 36 words only when preserving an unusually important technical distinction');
});

it('instructs one principal accomplishment per bullet and against enumerating every supported detail', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('Each bullet should carry one principal accomplishment, system, or theme')
        ->toContain('Do not enumerate every supported implementation detail merely because the cited facts permit it')
        ->toContain('do not combine unrelated facts solely to maximize how much of the evidence you use');
});

it('states concise does not mean generic', function () {
    expect(normalizedResumeWordingSystemPrompt())->toContain('Concise does not mean generic');
});

// --- v1.3: checklist synthesis, stronger verb variation, Summary ownership scope ---
//
// Fixes three behaviors observed across multiple live Pearly
// generations under v1.2: (1) bullets that enumerate every stage/
// safeguard of one system as a checklist rather than synthesizing the
// strongest details (e.g. the RocketGate remediation bullet's "safely
// planned, executed, verified, recovered from, and audited"); (2) two
// consecutive same-role bullets both opening with "Led" despite the
// existing verb-variation guidance; (3) the Summary generalizing a
// project-scoped "independently implemented" fact into a career-wide
// "independently delivers production systems" claim.

it('instructs synthesizing stage/safeguard/mechanics facts into one coherent accomplishment rather than listing every step', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('When several cited facts describe stages, safeguards, or mechanics of one system')
        ->toContain('synthesize the most differentiating details into a coherent accomplishment rather than listing every supported step')
        ->toContain('Prefer the few details that best establish technical difficulty, safety, scale, or outcome');
});

it('requires checklist synthesis to preserve guardrail framing and metric scope rather than dropping it', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('it must never cause a guardrail-bearing detail or a Metric\'s own required scope/qualifier to disappear')
        ->toContain('it must still carry exactly the framing its guardrail and scope_note require');
});

it('tells the Summary to prefer direct positioning over broad self-sufficiency claims, without banning supported project-level independence wording', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('Prefer direct candidate-positioning language over broad self-sufficiency claims')
        ->toContain('Do not generalize project-specific independent ownership into a career-wide statement such as "independently delivers production systems" unless the selected evidence supports independence at that broader scope')
        ->toContain('This does not prohibit supported project-level wording such as "independently designed/implemented" where the selected CareerFacts explicitly authorize it');
});

it('applies the same length/focus guidance to a Selected Project bullet', function () {
    expect(normalizedResumeWordingSystemPrompt())
        ->toContain("This same length/focus guidance applies to a Selected Project's one bullet");
});

it('explicitly prevents repetitive same-verb bullet openings within a role', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('do not begin consecutive bullets with the same verb when a truthful, natural alternative is available')
        ->toContain("do not start a large majority of one role's bullets with the same verb");
});

it('forbids mechanical synonym rotation while still requiring truthful, natural variation', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('Do not mechanically rotate synonyms merely to satisfy this preference')
        ->toContain('only vary when the actual work supports the different verb you choose')
        ->toContain('never force a variation so unnatural that it misdescribes the work');
});

it('offers example verbs without mandating mechanical rotation, and defers to ownership guardrails', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('Engineered, Developed, Designed, Implemented, Created, Automated, Delivered, Modernized, Integrated, Led, Partnered, Collaborated')
        ->toContain('not a fixed list to rotate through mechanically')
        ->toContain('not an instruction to inflate ownership')
        ->toContain('the sole-credit/ownership guardrails below remain authoritative');
});

it('never swaps in a stronger verb than the evidence describes', function () {
    expect(normalizedResumeWordingSystemPrompt())
        ->toContain('never swap in a stronger-sounding verb than the evidence describes');
});

it('states the Summary word-count target and its information hierarchy', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('Target approximately 35-50 words')
        ->toContain('Refocus the summary on recruiter positioning rather than compressing as many impressive facts as will fit')
        ->toContain('(1) what kind of candidate this is, (2) what kinds of systems/problems they work on, (3) target-relevant technical/capability breadth')
        ->toContain('unmistakably read as a senior full-stack/software candidate before it dives into a specialized example such as transaction remediation')
        ->toContain('does not need to contain the resume\'s largest metric');
});

it('still contains every pre-existing truthfulness/guardrail rule unchanged', function () {
    $systemPrompt = normalizedResumeWordingSystemPrompt();

    expect($systemPrompt)
        ->toContain('Never state or imply a technology, scope, outcome, or accomplishment that isn\'t directly present')
        ->toContain('Never state a computed candidate-years figure')
        ->toContain('Never combine facts across different Employers/Roles/Projects')
        ->toContain('None of these exact terms')
        ->toContain('Respond with exactly one summary, one bullet entry per supplied bullet group');
});
