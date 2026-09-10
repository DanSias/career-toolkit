<?php

use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV1;

/**
 * Guards the prompt-quality fix made after the first live Pearly
 * evaluation: the system prompt's display-title explanation had used
 * Daniel's real canonical Pearson title ("Data & Analytics Lead
 * Developer / Data Analyst") as an illustrative example — the same
 * literal string that also appears as real data in the candidate
 * payload — which is suspected of contributing to Resume Selection
 * attaching that role's real title/projects to a sibling Pearson
 * role's role_id. The fix replaces the example with clearly synthetic
 * values and adds an explicit role/project-binding instruction.
 *
 * Assertions are made against whitespace-normalized text (the raw
 * heredoc wraps mid-sentence) so they stay robust to future rewording
 * that doesn't change the wrap points.
 */
function normalizedResumeSelectionSystemPrompt(): string
{
    return preg_replace('/\s+/', ' ', (new ResumeSelectionPromptV1)->systemPrompt());
}

it('does not use a real candidate title as an illustrative example', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->not->toContain('Data & Analytics Lead Developer')
        ->not->toContain('Data Analyst');
});

it('states that every project_id must belong to the same role_id as its entry', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)->toContain('every `project_id` in its bullet groups must be a project that actually belongs to that same `role_id`');
});

it('explicitly warns against mixing or swapping sibling roles at the same employer', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('sibling role at the same employer')
        ->toContain('never combine or swap them');
});

it('uses a clearly synthetic example for the title_choice rule', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('Senior Engineer / Technical Lead')
        ->toContain('segment_1')
        ->toContain('segment_2')
        ->toContain('="Senior Engineer"')
        ->toContain('="Technical Lead"');
});

it('tells the model to choose title_choice instead of writing title text', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('You do not write or abbreviate a role\'s title')
        ->toContain('there is no mechanism to supply new or reworded title text');
});

/**
 * Guards the payload-grounding fix (ResumeCandidatePayloadBuilder now
 * emits real role_id/project_id directly in each fact's attribution):
 * the prompt must tell the model to use that supplied grounding rather
 * than inferring an id itself, since a second failed live attempt with
 * the role/project-binding prose above already in place showed prose
 * alone isn't sufficient — see docs/resume-variant-generation.md "Live
 * evaluation".
 */
it('tells the model to use the supplied role_id/project_id rather than infer one', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('Always use that supplied `role_id`/`project_id` value')
        ->toContain('Never infer an id from array order, chronology, employer grouping, or similarity between role titles');
});

// --- Bullet-allocation ownership fix ----------------------------------------
//
// Fixes a genuine prompt-contract defect found via live-eval composition
// investigation (Pearly ResumeVariant #4): the prompt previously told the
// model that "how many bullets a role gets space for" is "handled
// deterministically outside this response" — untrue. GenerateResumeVariant
// persists every bullet_groups entry the model proposes, one-to-one, with
// no cap/budget/trim step of any kind; only role ORDER (reverse-chronological
// by start date) is actually deterministic. The old sentence likely reduced
// the model's felt responsibility for bullet-count balance across roles
// while nothing else in the system carried that responsibility either.

it('does not claim bullet-count-per-role is handled deterministically outside the response', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)->not->toContain('how many bullets a role gets space');
});

it('explicitly states bullet allocation per role is the model\'s own responsibility', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('how many bullets each role receives is part of your selection responsibility')
        ->toContain('nothing later automatically balances or caps bullet allocation');
});

it('still states role ordering itself is deterministic, reverse-chronological by start date', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)->toContain('Role ordering itself is handled deterministically outside this response (always reverse-chronological by role start date)');
});

it('keeps the every-role floor of at least one bullet', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('must appear in `experience` with')
        ->toContain('at least one bullet group');
});

it('does not impose a mechanical minimum-two-bullets-per-role rule', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->not->toContain('at least two bullet')
        ->not->toContain('minimum of two bullet')
        ->not->toContain('two bullets per role');
});

it('does not impose a fixed per-role maximum bullet count', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->not->toContain('at most one bullet')
        ->not->toContain('no more than')
        ->not->toContain('maximum of');
});

it('states that every-role-gets-one-bullet is a floor, not a target, and guides depth for substantial earlier roles', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('Every eligible role receiving one bullet is a floor, not a target')
        ->toContain('prefer strong differentiated evidence from a substantial earlier role over adding another lower-value bullet to an already well-covered role')
        ->toContain('Tenure, technical scope, distinctive work, and concrete quantified outcomes can justify additional bullets for earlier roles')
        ->toContain('This is a judgment call, not a fixed per-role quota');
});

it('tells the model it is selecting for a two-page resume, without exposing rendered page geometry', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('You are selecting evidence for a two-page professional resume')
        ->toContain('Select enough differentiated evidence to make strong use of a two-page resume')
        ->not->toContain('utilization')
        ->not->toContain('trailing whitespace')
        ->not->toContain('line count');
});

it('states content quality outranks page filling', function () {
    $systemPrompt = normalizedResumeSelectionSystemPrompt();

    expect($systemPrompt)
        ->toContain('do not add weak, repetitive, or low-relevance evidence merely to fill space')
        ->toContain('Content quality outranks page filling');
});
