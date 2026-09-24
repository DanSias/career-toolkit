<?php

use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV1;
use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV2;

/**
 * Guards the TWO intended semantic changes from ResumeWordingPromptV1,
 * both made in place on this same class (never a new class per change)
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

it('is byte-identical to V1 everywhere except the two inserted clauses — proven by removing exactly those clauses and comparing the remainder', function () {
    $v1 = preg_replace('/\s+/', ' ', (new ResumeWordingPromptV1)->systemPrompt());
    $v2 = normalizedResumeWordingV2SystemPrompt();

    $summaryEvidenceBoundaryClause = "Draw (3)'s technical/capability breadth only from "
        .'the summary evidence supplied above — never from a technology '
        .'or capability that is only supported by evidence supplied to a '
        .'different bullet or Selected Project elsewhere in this same '
        .'request, even when it is genuinely true of the candidate. ';

    $quantifiedFigureSeparationClause = '- When a bullet or the summary states more than one quantified '
        .'figure, keep each figure clearly attached to the single outcome or '
        .'scope it actually measures. Never phrase two figures so closely '
        .'together that a reader could plausibly misread a guardrail-sensitive '
        .'figure as modifying the other figure\'s claim instead of its own. ';

    expect($v2)->toContain($summaryEvidenceBoundaryClause)
        ->and($v2)->toContain($quantifiedFigureSeparationClause);

    $remainder = str_replace([$summaryEvidenceBoundaryClause, $quantifiedFigureSeparationClause], '', $v2);

    expect($remainder)->toBe($v1);
});
