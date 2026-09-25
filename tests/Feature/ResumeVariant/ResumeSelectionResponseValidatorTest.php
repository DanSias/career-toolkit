<?php

use App\Exceptions\InvalidResumeVariantResponseException;
use App\Support\ResumeVariant\ResumeSelectionResponseValidator;

/**
 * Focused on the new provider-neutral content-budget invariants
 * (MAX_EXPERIENCE_BULLET_GROUPS / MAX_SELECTED_SKILLS) added to close
 * the observed Formic over-selection (17 bullets / 22 Skills, ~3
 * rendered pages against a two-page target) — see
 * ResumeSelectionResponseValidator's own docblock for the renderer
 * evidence. No dedicated unit-test file existed for this validator
 * before this change; this file is scoped to the budget invariants and
 * their immediate interactions (role completeness, Selected Projects),
 * not a full re-test of every pre-existing invariant already exercised
 * end-to-end via GenerateResumeVariantTest.php.
 */
function selectionValidator(): ResumeSelectionResponseValidator
{
    return new ResumeSelectionResponseValidator;
}

/**
 * @param  array<int, int>  $bulletGroupsPerRole  role_id => bullet-group count for that role.
 * @return array<string, mixed>
 */
function selectionResponseWithBulletGroups(array $bulletGroupsPerRole, int $skillCount = 1): array
{
    $factCounter = 0;
    $experience = [];

    foreach ($bulletGroupsPerRole as $roleId => $count) {
        $bulletGroups = [];

        for ($i = 0; $i < $count; $i++) {
            $factCounter++;
            $bulletGroups[] = [
                'project_id' => -1,
                'order' => $i + 1,
                'career_fact_keys' => ["fact-{$factCounter}"],
                'job_analysis_finding_ids' => [],
            ];
        }

        $experience[] = [
            'role_id' => $roleId,
            'title_choice' => 'full',
            'bullet_groups' => $bulletGroups,
        ];
    }

    $skills = [];
    for ($i = 1; $i <= $skillCount; $i++) {
        $skills[] = ['skill_id' => $i, 'order' => $i];
    }

    return [
        'summary_evidence' => [],
        'skills' => $skills,
        'experience' => $experience,
        'selected_projects' => [],
        'target_term_usages' => [],
    ];
}

function validateSelectionResponse(array $response, array $overrides = []): array
{
    $totalFacts = 0;
    foreach ($response['experience'] as $role) {
        $totalFacts += count($role['bullet_groups']);
    }
    $validFactKeys = array_map(fn (int $i) => "fact-{$i}", range(1, max($totalFacts, 1)));

    $skillCount = count($response['skills']);
    $validSkillIds = range(1, max($skillCount, 1));

    $roleIds = array_column($response['experience'], 'role_id');
    $titleChoicesByRole = array_combine($roleIds, array_fill(0, count($roleIds), ['full' => 'Some Title']));

    return selectionValidator()->validate(
        $response,
        $overrides['validRoleIds'] ?? $roleIds,
        $overrides['validFactKeys'] ?? $validFactKeys,
        $overrides['validSkillIds'] ?? $validSkillIds,
        $overrides['roleIdByProjectId'] ?? [],
        $overrides['validFindingIds'] ?? [],
        $overrides['titleChoicesByRole'] ?? $titleChoicesByRole,
        $overrides['directEvidenceExistsByTerm'] ?? [],
        $overrides['resumeEligibleRoleIds'] ?? $roleIds,
        $overrides['validIndependentProjectIds'] ?? [],
        $overrides['projectIdByFactKey'] ?? [],
    );
}

// --- Experience bullet-group budget -----------------------------------

it('accepts exactly the maximum of 13 total Experience bullet groups', function () {
    $response = selectionResponseWithBulletGroups([1 => 12, 2 => 1]);

    $validated = validateSelectionResponse($response);

    expect($validated['experience'])->toHaveCount(2);
});

it('rejects 14 total Experience bullet groups — one over the maximum', function () {
    $response = selectionResponseWithBulletGroups([1 => 13, 2 => 1]);

    expect(fn () => validateSelectionResponse($response))
        ->toThrow(InvalidResumeVariantResponseException::class);
});

it('rejects the bullet-group maximum regardless of how the total is distributed across roles', function () {
    $response = selectionResponseWithBulletGroups([1 => 7, 2 => 7]);

    expect(fn () => validateSelectionResponse($response))
        ->toThrow(InvalidResumeVariantResponseException::class);
});

it('accepts a 13-bullet total split unevenly across three roles', function () {
    $response = selectionResponseWithBulletGroups([1 => 11, 2 => 1, 3 => 1]);

    $validated = validateSelectionResponse($response);

    expect($validated['experience'])->toHaveCount(3);
});

it('still enforces role completeness alongside the new budget — a missing resume-eligible role fails even under budget', function () {
    $response = selectionResponseWithBulletGroups([1 => 2]);

    expect(fn () => validateSelectionResponse($response, ['resumeEligibleRoleIds' => [1, 2]]))
        ->toThrow(InvalidResumeVariantResponseException::class);
});

it('does not count Selected Projects toward the Experience bullet-group budget', function () {
    $response = selectionResponseWithBulletGroups([1 => 12, 2 => 1]);
    $response['selected_projects'] = [
        ['project_id' => 100, 'order' => 1, 'career_fact_keys' => ['project-fact']],
    ];

    $validated = validateSelectionResponse($response, [
        'validFactKeys' => [...array_map(fn (int $i) => "fact-{$i}", range(1, 13)), 'project-fact'],
        'validIndependentProjectIds' => [100],
        'projectIdByFactKey' => ['project-fact' => 100],
    ]);

    expect($validated['experience'])->toHaveCount(2)
        ->and($validated['selected_projects'])->toHaveCount(1);
});

// --- Skills budget ------------------------------------------------------

it('accepts exactly the maximum of 18 selected Skills', function () {
    $response = selectionResponseWithBulletGroups([1 => 1], skillCount: 18);

    $validated = validateSelectionResponse($response);

    expect($validated['skills'])->toHaveCount(18);
});

it('rejects 19 selected Skills — one over the maximum', function () {
    $response = selectionResponseWithBulletGroups([1 => 1], skillCount: 19);

    expect(fn () => validateSelectionResponse($response))
        ->toThrow(InvalidResumeVariantResponseException::class);
});

it('keeps the Skills budget independent of the Experience bullet-group budget', function () {
    $response = selectionResponseWithBulletGroups([1 => 13], skillCount: 18);

    $validated = validateSelectionResponse($response);

    expect($validated['experience'][0]['bullet_groups'])->toHaveCount(13)
        ->and($validated['skills'])->toHaveCount(18);
});

// --- Experience fact/project consistency --------------------------------
//
// Closes the exact gap a live qwen3.8:27b evaluation exposed: the
// pre-existing assertRoleAndProjectValidity() only checks that a bullet
// group's declared project_id belongs to its role_id — it never checked
// that each CITED FACT is itself attributed to that declared project.
// The failed response mixed a sibling-role project into role_id=3
// (caught by the pre-existing check) but ALSO mixed same-role,
// sibling-PROJECT facts into three other bullet groups (RocketGate
// Transaction Toolkit tagged as Verbatim; Pearson Email Marketing
// Tracker tagged as Marketing Forecast; Pearson Recruitment Agent
// Tracking tagged as Salesforce Migration) — all invisible to the
// validator until now. The chosen semantics (see
// assertExperienceFactProjectConsistency()'s own docblock) are
// evidence-based, not guessed: the two real, already-persisted,
// human-accepted Formic selections never mix a role-level fact into a
// project-specific bullet group, so that combination is rejected here
// exactly like a sibling-project fact — a `-1` bullet group remains
// unconstrained.

/**
 * @param  array<int, string>  $factKeys
 * @return array<string, mixed>
 */
function experienceGroup(int $roleId, int $projectId, array $factKeys, string $titleChoice = 'full'): array
{
    return [
        'summary_evidence' => [],
        'skills' => [],
        'experience' => [[
            'role_id' => $roleId,
            'title_choice' => $titleChoice,
            'bullet_groups' => [[
                'project_id' => $projectId,
                'order' => 1,
                'career_fact_keys' => $factKeys,
                'job_analysis_finding_ids' => [],
            ]],
        ]],
        'selected_projects' => [],
        'target_term_usages' => [],
    ];
}

it('accepts a project-specific bullet whose fact is genuinely attributed to that exact project', function () {
    $response = experienceGroup(roleId: 1, projectId: 10, factKeys: ['fact-a']);

    $validated = validateSelectionResponse($response, [
        'validFactKeys' => ['fact-a'],
        'roleIdByProjectId' => [10 => 1],
        'projectIdByFactKey' => ['fact-a' => 10],
    ]);

    expect($validated['experience'][0]['bullet_groups'][0]['project_id'])->toBe(10);
});

it('still rejects when the declared project belongs to a different role (pre-existing behavior preserved)', function () {
    $response = experienceGroup(roleId: 3, projectId: 6, factKeys: ['fact-a']);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['fact-a'],
        'roleIdByProjectId' => [6 => 2], // project 6 really belongs to role 2
        'projectIdByFactKey' => ['fact-a' => 6],
    ]))->toThrow(InvalidResumeVariantResponseException::class);
});

it('rejects when the declared project belongs to the right role but a cited fact belongs to a sibling project — the exact gap this closes', function () {
    // Mirrors the real failure: role 1 owns both project 2 (Verbatim)
    // and project 3 (Transaction Toolkit); the bullet declares project
    // 2 but cites a fact truly attributed to project 3.
    $response = experienceGroup(roleId: 1, projectId: 2, factKeys: ['verbatim-fact', 'toolkit-fact-mistagged']);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['verbatim-fact', 'toolkit-fact-mistagged'],
        'roleIdByProjectId' => [2 => 1, 3 => 1],
        'projectIdByFactKey' => ['verbatim-fact' => 2, 'toolkit-fact-mistagged' => 3],
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'is not attributed to project_id [2]');
});

it('accepts multiple facts that all genuinely belong to the same declared project', function () {
    $response = experienceGroup(roleId: 1, projectId: 2, factKeys: ['fact-a', 'fact-b', 'fact-c']);

    $validated = validateSelectionResponse($response, [
        'validFactKeys' => ['fact-a', 'fact-b', 'fact-c'],
        'roleIdByProjectId' => [2 => 1],
        'projectIdByFactKey' => ['fact-a' => 2, 'fact-b' => 2, 'fact-c' => 2],
    ]);

    expect($validated['experience'][0]['bullet_groups'][0]['career_fact_keys'])->toHaveCount(3);
});

it('accepts a role with no owned projects using project_id=-1', function () {
    $response = experienceGroup(roleId: 3, projectId: -1, factKeys: ['seo-fact']);

    $validated = validateSelectionResponse($response, [
        'validFactKeys' => ['seo-fact'],
        'roleIdByProjectId' => [],
        'projectIdByFactKey' => [], // role-level fact, no project attribution
    ]);

    expect($validated['experience'][0]['bullet_groups'][0]['project_id'])->toBe(-1);
});

it('rejects a role with no owned projects borrowing a sibling role\'s project — the exact real Formic failure', function () {
    $response = experienceGroup(roleId: 3, projectId: 6, factKeys: ['seo-fact']);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['seo-fact'],
        'roleIdByProjectId' => [6 => 2], // project 6 belongs to sibling role 2, not role 3
        'projectIdByFactKey' => [],
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'project_id [6] does not belong to role_id [3]');
});

it('allows a -1 ("no specific project") bullet group to cite a project-attributed fact — permissive by design, per the chosen semantics', function () {
    $response = experienceGroup(roleId: 1, projectId: -1, factKeys: ['project-attributed-fact']);

    $validated = validateSelectionResponse($response, [
        'validFactKeys' => ['project-attributed-fact'],
        'roleIdByProjectId' => [2 => 1],
        'projectIdByFactKey' => ['project-attributed-fact' => 2],
    ]);

    expect($validated['experience'][0]['bullet_groups'][0]['project_id'])->toBe(-1);
});

it('rejects a project-specific bullet group citing a role-level (unattributed) fact — strict by design, matching real historical Formic selections', function () {
    $response = experienceGroup(roleId: 1, projectId: 2, factKeys: ['role-level-fact']);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['role-level-fact'],
        'roleIdByProjectId' => [2 => 1],
        'projectIdByFactKey' => [], // role-level: no project attribution at all
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'is not attributed to project_id [2]');
});

// --- Target-term location/evidence integrity --------------------------
//
// Added after a real, already-persisted Formic ResumeVariant
// (resume-selection-v1.5, id 1) was found, on inspection, to have a
// target_term_usages entry located at one bullet group while citing a
// CareerFact that actually belonged to a different bullet group
// entirely — undetected because location referential integrity and
// evidence referential integrity were checked independently, never
// against each other. See
// ResumeSelectionResponseValidator::assertUsageEvidenceIsLocal()'s own
// docblock and docs/resume-variant-generation.md "Target-term location
// integrity". That historical row remains historically invalid under
// this new rule — this validator governs future generation only.

/**
 * Builds an experience tree with explicit control over exactly which
 * CareerFact keys back each bullet group — unlike
 * selectionResponseWithBulletGroups() above (one fact per bullet,
 * sequentially numbered), this lets a test declare multiple facts per
 * bullet group, needed for the "multiple local facts" / "mixed
 * local+foreign" cases below.
 *
 * @param  array<int, array<int, array<int, string>>>  $factKeysByRoleAndBulletIndex  role_id => [bullet_group_index => career_fact_key[]]
 * @return array<string, mixed>
 */
function selectionResponseWithBulletFacts(array $factKeysByRoleAndBulletIndex, array $summaryEvidence = []): array
{
    $experience = [];

    foreach ($factKeysByRoleAndBulletIndex as $roleId => $bulletFactKeys) {
        $bulletGroups = [];

        foreach ($bulletFactKeys as $index => $keys) {
            $bulletGroups[] = [
                'project_id' => -1,
                'order' => $index + 1,
                'career_fact_keys' => $keys,
                'job_analysis_finding_ids' => [],
            ];
        }

        $experience[] = ['role_id' => $roleId, 'title_choice' => 'full', 'bullet_groups' => $bulletGroups];
    }

    return [
        'summary_evidence' => $summaryEvidence,
        'skills' => [],
        'experience' => $experience,
        'selected_projects' => [],
        'target_term_usages' => [],
    ];
}

/**
 * Every CareerFact key referenced anywhere in a response built by
 * selectionResponseWithBulletFacts() — used to build a validFactKeys
 * override wide enough to cover deliberately "foreign" keys a test
 * wants to exist globally but reject locally.
 *
 * @param  array<string, mixed>  $response
 * @return array<int, string>
 */
function allFactKeysIn(array $response): array
{
    $keys = $response['summary_evidence'];

    foreach ($response['experience'] as $role) {
        foreach ($role['bullet_groups'] as $group) {
            $keys = [...$keys, ...$group['career_fact_keys']];
        }
    }

    return array_values(array_unique($keys));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function targetTermUsage(array $overrides = []): array
{
    return array_merge([
        'term' => 'Azure',
        'job_analysis_finding_id' => 1,
        'posture' => 'capability', // avoids needing directEvidenceExistsByTerm for tests that aren't about that check
        'location_type' => 'bullet',
        'role_id' => 1,
        'bullet_group_index' => 0,
        'relationship_phrase_key' => 'not_applicable',
        'career_fact_keys' => [],
    ], $overrides);
}

it('accepts a bullet-location target-term usage citing only evidence declared at that exact bullet group', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a']]]);
    $response['target_term_usages'] = [targetTermUsage(['role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['fact-a']])];

    $validated = validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response), 'validFindingIds' => [1]]);

    expect($validated)->toBeArray();
});

it('rejects a target-term usage citing evidence from ANOTHER bullet group in the same role', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a'], ['fact-b']]]);
    $response['target_term_usages'] = [targetTermUsage(['role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['fact-b']])];

    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response)]))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is not part of the evidence already declared at role_id [1] bullet_group_index [0]');
});

it('rejects a target-term usage citing evidence from a DIFFERENT role entirely', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a']], 2 => [['fact-b']]]);
    $response['target_term_usages'] = [targetTermUsage(['role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['fact-b']])];

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => allFactKeysIn($response),
        'resumeEligibleRoleIds' => [1, 2],
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'is not part of the evidence already declared at role_id [1] bullet_group_index [0]');
});

it('accepts a target-term usage citing multiple facts that are ALL local to the declared bullet group', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a', 'fact-b', 'fact-c']]]);
    $response['target_term_usages'] = [targetTermUsage(['role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['fact-a', 'fact-c']])];

    $validated = validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response), 'validFindingIds' => [1]]);

    expect($validated)->toBeArray();
});

it('rejects a target-term usage mixing one local fact with one foreign fact', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a'], ['fact-foreign']]]);
    $response['target_term_usages'] = [targetTermUsage(['role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['fact-a', 'fact-foreign']])];

    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response)]))
        ->toThrow(InvalidResumeVariantResponseException::class, 'career_fact_key [fact-foreign] is not part of the evidence already declared at role_id [1] bullet_group_index [0]');
});

it('accepts a summary-location target-term usage citing only evidence declared as Summary evidence', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a']]], summaryEvidence: ['fact-summary']);
    $response['target_term_usages'] = [targetTermUsage([
        'location_type' => 'summary', 'role_id' => -1, 'bullet_group_index' => -1, 'career_fact_keys' => ['fact-summary'],
    ])];

    $validated = validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response), 'validFindingIds' => [1]]);

    expect($validated)->toBeArray();
});

it('rejects a summary-location target-term usage borrowing evidence that only a bullet group declared', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a']]], summaryEvidence: ['fact-summary']);
    $response['target_term_usages'] = [targetTermUsage([
        'location_type' => 'summary', 'role_id' => -1, 'bullet_group_index' => -1, 'career_fact_keys' => ['fact-a'],
    ])];

    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response)]))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is not part of the evidence already declared at the Summary');
});

it('rejects a bullet-location target-term usage borrowing evidence that only Summary declared', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a']]], summaryEvidence: ['fact-summary']);
    $response['target_term_usages'] = [targetTermUsage(['role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['fact-summary']])];

    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response)]))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is not part of the evidence already declared at role_id [1] bullet_group_index [0]');
});

it('still rejects a target-term usage citing a CareerFact key that does not exist anywhere — pre-existing referential-integrity check preserved', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a']]]);
    $response['target_term_usages'] = [targetTermUsage(['role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['never-supplied']])];

    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response)]))
        ->toThrow(InvalidResumeVariantResponseException::class, 'career_fact_key [never-supplied] was not supplied in the provider input.');
});

it('still rejects an unauthorized direct posture alongside the new locality check — both checks run independently', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a']]]);
    $response['target_term_usages'] = [targetTermUsage([
        'posture' => 'direct', 'role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['fact-a'],
    ])];

    // No entry in directEvidenceExistsByTerm for 'Azure' => not authorized.
    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response)]))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is not authorized for a direct claim');
});

it('still enforces qualified/capability relationship_phrase_key consistency alongside the new locality check', function () {
    $response = selectionResponseWithBulletFacts([1 => [['fact-a']]]);
    $response['target_term_usages'] = [targetTermUsage([
        'posture' => 'qualified', 'relationship_phrase_key' => 'not_applicable', // invalid: qualified needs a real phrase
        'role_id' => 1, 'bullet_group_index' => 0, 'career_fact_keys' => ['fact-a'],
    ])];

    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response)]))
        ->toThrow(InvalidResumeVariantResponseException::class, 'A qualified usage must supply a real relationship phrase');
});
