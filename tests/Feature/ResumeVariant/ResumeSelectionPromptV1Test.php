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
