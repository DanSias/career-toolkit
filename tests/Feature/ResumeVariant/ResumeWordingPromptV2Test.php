<?php

use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV1;
use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV2;

/**
 * Guards the FOUR intended semantic changes from ResumeWordingPromptV1,
 * all made in place on this same class (never a new class per change)
 * because `resume-wording-v2` itself has never been persisted — see
 * ResumeWordingPromptV2's own class docblock, which documents each
 * change's origin in full:
 *
 * 1. A clarifying sentence in `## Summary`, added after the first live
 *    qwen3.8:27b Resume Wording evaluation showed a generated summary
 *    borrowing "React" and "Laravel" from evidence supplied to other
 *    bullets/projects in the same request, not the summary's own
 *    evidence.
 * 2. A new bullet in `## Never invent, never compute, never
 *    overstate`, added after a qualitative review of two independent
 *    live qwen3.8:27b generations found the same guardrail-sensitive
 *    figure placed immediately adjacent to a different quantified
 *    claim in one sentence — grammatically defensible but an avoidable
 *    fast-read ambiguity about which figure a sentence is actually
 *    claiming. This is prompt guidance only, addressing semantic
 *    readability — it is not a new deterministic validator rule; see
 *    docs/resume-variant-generation.md "Metric-quantity separation."
 * 3. A new `## Direct target-term guidance` section, explaining the
 *    fact-local `direct_target_terms`/`summary_direct_target_terms`
 *    input fields — encouragement/authorization at their exact
 *    approved location, never a requirement, never a second evidence
 *    source. See docs/resume-variant-generation.md "Target-term
 *    location integrity."
 * 4. A new paragraph in `## Summary`, added after a corrected live
 *    qwen3.8:27b run again leaked cross-location canonical Skills
 *    (this time "React"/"Node.js"/"Laravel"/"TypeScript") despite
 *    change 1 above already stating the rule in prose — 2 of 3 known
 *    qwen runs violated it under byte-identical prompt text, i.e.
 *    stochastic prompt-only compliance. Explains the new
 *    `summary_authorized_skills` input field (the same authorization
 *    `ResumeWordingResponseValidator` itself computes, surfaced as a
 *    generation-time affordance) and explicitly resolves the tension
 *    with change 1's neighboring "unmistakably read as a senior
 *    full-stack/software candidate" sentence: use qualitative language
 *    instead of naming an unauthorized technology. See
 *    docs/resume-variant-generation.md "Skill provenance."
 *
 * A new class rather than an in-place bump of V1's `resume-wording-v1.3`
 * string because that version has already reached real, persisted
 * production use — mirrors ResumeSelectionPromptV2Test.php's own "new
 * class, not an in-place bump" reasoning.
 *
 * Assertions are made against whitespace-normalized text (the raw
 * heredoc wraps mid-sentence) so they stay robust to rewording that
 * doesn't change the wrap points.
 */
function normalizedResumeWordingV2SystemPrompt(): string
{
    return preg_replace('/\s+/', ' ', (new ResumeWordingPromptV2)->systemPrompt());
}

it('bumps the prompt version for this new class', function () {
    expect((new ResumeWordingPromptV2)->version())->toBe('resume-wording-v2');
});

it('does not change the schema version — no schema field changed, only prompt guidance', function () {
    expect((new ResumeWordingPromptV2)->schemaVersion())->toBe('1.1');
});

it('produces the byte-identical JSON schema V1 produces', function () {
    $v1 = (new ResumeWordingPromptV1)->jsonSchema([1, 2], [9]);
    $v2 = (new ResumeWordingPromptV2)->jsonSchema([1, 2], [9]);

    expect($v2)->toBe($v1);
});

it('produces the byte-identical user prompt V1 produces for the same input', function () {
    $approvedSelection = ['summary_evidence' => [], 'experience' => [], 'selected_projects' => []];
    $denylistTerms = ['Apollo'];

    expect((new ResumeWordingPromptV2)->userPrompt($approvedSelection, $denylistTerms))
        ->toBe((new ResumeWordingPromptV1)->userPrompt($approvedSelection, $denylistTerms));
});

it('tells the model to draw Summary technical/capability breadth only from the summary evidence supplied, even when other true technologies appear elsewhere in the same request', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)
        ->toContain("Draw (3)'s technical/capability breadth only from the summary evidence supplied above")
        ->toContain('never from a technology or capability that is only supported by evidence supplied to a different bullet or Selected Project elsewhere in this same request')
        ->toContain('even when it is genuinely true of the candidate');
});

it('keeps the Summary word-count target and information hierarchy unchanged', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)
        ->toContain('Target approximately 35-50 words')
        ->toContain('(1) what kind of candidate this is, (2) what kinds of systems/problems they work on, (3) target-relevant technical/capability breadth')
        ->toContain('unmistakably read as a senior full-stack/software candidate before it dives into a specialized example such as transaction remediation');
});

it('tells the model to keep multiple quantified figures in one sentence unambiguously attached to their own outcome', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)
        ->toContain('When a bullet or the summary states more than one quantified figure, keep each figure clearly attached to the single outcome or scope it actually measures')
        ->toContain("Never phrase two figures so closely together that a reader could plausibly misread a guardrail-sensitive figure as modifying the other figure's claim instead of its own");
});

it('states the quantified-figure rule generically, naming no specific CareerFact, figure, or example', function () {
    $systemPrompt = (new ResumeWordingPromptV2)->systemPrompt();

    // The rule that motivated this (a real Nexus $25M+ figure sitting
    // next to a different hours-saved figure) must never itself appear
    // in the production prompt text — the instruction generalizes, it
    // does not special-case the fact that prompted it.
    expect($systemPrompt)
        ->not->toContain('Nexus')
        ->not->toContain('$25M')
        ->not->toContain('25M')
        ->not->toContain('nexus-')
        ->not->toContain('marketing spend');
});

it('still contains the pre-existing evidence-boundary clause introduced for the Summary borrowing fix', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)
        ->toContain("Draw (3)'s technical/capability breadth only from the summary evidence supplied above")
        ->toContain('never from a technology or capability that is only supported by evidence supplied to a different bullet or Selected Project elsewhere in this same request')
        ->toContain('even when it is genuinely true of the candidate');
});

it('tells the model that a location\'s direct_target_terms are encouraged/authorized there, never required, never a separate evidence source, and never authorized elsewhere', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)
        ->toContain('## Direct target-term guidance')
        ->toContain('`direct_target_terms`')
        ->toContain('`summary_direct_target_terms`')
        ->toContain('Use one only when it can be stated naturally and accurately from that exact location\'s own supplied evidence')
        ->toContain('This is not a separate evidence source')
        ->toContain('it never authorizes importing a fact, technology, ownership claim, depth, duration, scale, or metric from anywhere else')
        ->toContain('A term listed for one location is not authorized at any other location')
        ->toContain('Using a listed term is encouraged when it genuinely fits; it is not required')
        ->not->toContain('must appear')
        ->not->toContain('MUST appear')
        ->not->toContain('required to use');
});

it('excludes qualified/capability target terms from the direct_target_terms guidance — both are handled by separate, pre-existing mechanisms', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)->toContain(
        'Terms handled through a qualified comparison, or terms that must never appear at all, are handled through separate mechanisms and are never included in these lists.'
    );
});

it('tells the model summary_authorized_skills is the closed-world list of canonical Skills it may name in the summary', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)
        ->toContain('`summary_authorized_skills`')
        ->toContain('the exact, closed-world set of canonical Skill names you may name in the summary');
});

it('tells the model never to name a canonical Skill absent from summary_authorized_skills, even when true and visible elsewhere in the request', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)
        ->toContain('Never name a canonical Skill absent from that list, even when it appears elsewhere in this same request and is genuinely true of the candidate')
        ->toContain('seeing it elsewhere tells you what other locations describe, not what you may borrow');
});

it('explicitly allows qualitative full-stack/software language instead of naming an unauthorized technology', function () {
    $systemPrompt = normalizedResumeWordingV2SystemPrompt();

    expect($systemPrompt)
        ->toContain("If establishing the candidate's full-stack/software positioning would otherwise tempt you to name a specific technology that list doesn't include")
        ->toContain('describe that breadth qualitatively instead')
        ->toContain('"full-stack developer,"')
        ->toContain('"software systems,"')
        ->toContain('"web applications"')
        ->toContain('rather than reaching for a named technology from another location');
});

it('states the summary_authorized_skills rule generically, naming none of the technologies the recurring failure actually leaked', function () {
    $systemPrompt = (new ResumeWordingPromptV2)->systemPrompt();

    // The specific technologies that motivated this (React, Node.js,
    // Laravel, TypeScript) and the specific candidate/job (Formic) must
    // never appear in the production prompt text — the instruction
    // generalizes, it does not special-case the failure that prompted
    // it.
    expect($systemPrompt)
        ->not->toContain('React')
        ->not->toContain('Node.js')
        ->not->toContain('Laravel')
        ->not->toContain('TypeScript')
        ->not->toContain('Formic');
});

it('is byte-identical to V1 everywhere except the four inserted clauses — proven by removing exactly those clauses and comparing the remainder', function () {
    $v1 = preg_replace('/\s+/', ' ', (new ResumeWordingPromptV1)->systemPrompt());
    $v2 = normalizedResumeWordingV2SystemPrompt();

    $summaryEvidenceBoundaryClause = "Draw (3)'s technical/capability breadth only from "
        .'the summary evidence supplied above — never from a technology '
        .'or capability that is only supported by evidence supplied to a '
        .'different bullet or Selected Project elsewhere in this same '
        .'request, even when it is genuinely true of the candidate. ';

    $summaryAuthorizedSkillsClause = 'A `summary_authorized_skills` list is supplied '
        .'alongside the summary evidence — the exact, closed-world set '
        .'of canonical Skill names you may name in the summary. Never '
        .'name a canonical Skill absent from that list, even when it '
        .'appears elsewhere in this same request and is genuinely true '
        .'of the candidate; seeing it elsewhere tells you what other '
        .'locations describe, not what you may borrow. If establishing '
        ."the candidate's full-stack/software positioning would "
        .'otherwise tempt you to name a specific technology that list '
        ."doesn't include, describe that breadth qualitatively instead "
        .'— for example "full-stack developer," "software systems," or '
        .'"web applications" — rather than reaching for a named '
        .'technology from another location. ';

    $quantifiedFigureSeparationClause = '- When a bullet or the summary states more than one quantified '
        .'figure, keep each figure clearly attached to the single outcome or '
        .'scope it actually measures. Never phrase two figures so closely '
        .'together that a reader could plausibly misread a guardrail-sensitive '
        .'figure as modifying the other figure\'s claim instead of its own. ';

    $directTargetTermGuidanceSection = 'Direct target-term guidance '
        .'A location may include a `direct_target_terms` list (for the '
        .'summary, `summary_direct_target_terms`) — specific tailoring '
        .'terminology this location\'s own supplied evidence already '
        .'supports naming directly. Use one only when it can be stated '
        .'naturally and accurately from that exact location\'s own '
        .'supplied evidence, the same way you would choose any other '
        .'word. This is not a separate evidence source: it never '
        .'authorizes importing a fact, technology, ownership claim, '
        .'depth, duration, scale, or metric from anywhere else, and it '
        .'never overrides any other rule in this prompt. A term listed '
        .'for one location is not authorized at any other location, '
        .'even one where the same term happens to be listed too — '
        .'treat each location\'s list as its own. Using a listed term '
        .'is encouraged when it genuinely fits; it is not required, '
        .'and a location with no natural way to use one should simply '
        .'not use it. Terms handled through a qualified comparison, or '
        .'terms that must never appear at all, are handled through '
        .'separate mechanisms and are never included in these lists. ## ';

    expect($v2)->toContain($summaryEvidenceBoundaryClause)
        ->and($v2)->toContain($quantifiedFigureSeparationClause)
        ->and($v2)->toContain($directTargetTermGuidanceSection)
        ->and($v2)->toContain($summaryAuthorizedSkillsClause);

    $remainder = str_replace(
        [$summaryEvidenceBoundaryClause, $quantifiedFigureSeparationClause, $directTargetTermGuidanceSection, $summaryAuthorizedSkillsClause],
        '',
        $v2,
    );

    expect($remainder)->toBe($v1);
});
