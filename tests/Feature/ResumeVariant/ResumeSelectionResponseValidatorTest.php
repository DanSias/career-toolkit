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

    // Self-consistent by default: every CareerFact cited in a bullet
    // group is treated as eligible evidence for that bullet's own
    // declared role, so tests unrelated to Experience-role eligibility
    // (budgets, Skills, target-term locality, ...) aren't incidentally
    // rejected by assertBulletGroupCareerFactsAreEligibleForRole().
    // Tests that specifically exercise an eligibility mismatch pass an
    // explicit 'eligibleFactKeysForRole' override instead.
    $eligibleFactKeysForRole = [];
    foreach ($response['experience'] as $role) {
        $eligibleFactKeysForRole[$role['role_id']] ??= [];

        foreach ($role['bullet_groups'] as $group) {
            foreach ($group['career_fact_keys'] as $key) {
                $eligibleFactKeysForRole[$role['role_id']][$key] = true;
            }
        }
    }

    return selectionValidator()->validate(
        $response,
        $overrides['validRoleIds'] ?? $roleIds,
        $overrides['validFactKeys'] ?? $validFactKeys,
        $overrides['validSkillIds'] ?? $validSkillIds,
        $overrides['eligibleFactKeysForRole'] ?? $eligibleFactKeysForRole,
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

// --- Selected Projects budget (MAX_SELECTED_PROJECTS) --------------------
//
// Lowered from the original 0-3 schema cardinality (prompt-only "prefer
// 0-2") to a deterministic maximum of 1 after a read-only investigation
// measured that the existing MAX_EXPERIENCE_BULLET_GROUPS ceiling
// combined with 2 Selected Projects still renders past the two-page
// target, while combined with at most 1 it does not — see
// ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS's own
// docblock and docs/resume-variant-generation.md "PDF export".

/**
 * @param  array<int, int>  $independentProjectIds
 * @return array<int, array<string, mixed>>
 */
function selectedProjectsOf(array $independentProjectIds): array
{
    return array_map(fn (int $id, int $order) => [
        'project_id' => $id,
        'order' => $order + 1,
        'career_fact_keys' => ["project-fact-{$id}"],
    ], $independentProjectIds, array_keys($independentProjectIds));
}

it('accepts 0 Selected Projects', function () {
    $response = selectionResponseWithBulletGroups([1 => 1]);

    $validated = validateSelectionResponse($response);

    expect($validated['selected_projects'])->toHaveCount(0);
});

it('accepts exactly the maximum of 1 Selected Project', function () {
    $response = selectionResponseWithBulletGroups([1 => 1]);
    $response['selected_projects'] = selectedProjectsOf([100]);

    $validated = validateSelectionResponse($response, [
        'validFactKeys' => ['fact-1', 'project-fact-100'],
        'validIndependentProjectIds' => [100],
        'projectIdByFactKey' => ['project-fact-100' => 100],
    ]);

    expect($validated['selected_projects'])->toHaveCount(1);
});

it('rejects 2 Selected Projects — one over the maximum of 1', function () {
    $response = selectionResponseWithBulletGroups([1 => 1]);
    $response['selected_projects'] = selectedProjectsOf([100, 101]);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['fact-1', 'project-fact-100', 'project-fact-101'],
        'validIndependentProjectIds' => [100, 101],
        'projectIdByFactKey' => ['project-fact-100' => 100, 'project-fact-101' => 101],
    ]))->toThrow(InvalidResumeVariantResponseException::class);
});

it('rejects 3 Selected Projects — the old maximum is no longer accepted', function () {
    $response = selectionResponseWithBulletGroups([1 => 1]);
    $response['selected_projects'] = selectedProjectsOf([100, 101, 102]);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['fact-1', 'project-fact-100', 'project-fact-101', 'project-fact-102'],
        'validIndependentProjectIds' => [100, 101, 102],
        'projectIdByFactKey' => ['project-fact-100' => 100, 'project-fact-101' => 101, 'project-fact-102' => 102],
    ]))->toThrow(InvalidResumeVariantResponseException::class);
});

it('still rejects a Selected Project whose project_id is not a valid independent Project — pre-existing behavior preserved', function () {
    $response = selectionResponseWithBulletGroups([1 => 1]);
    $response['selected_projects'] = selectedProjectsOf([999]);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['fact-1', 'project-fact-999'],
        'validIndependentProjectIds' => [100], // 999 is not in this set
        'projectIdByFactKey' => ['project-fact-999' => 999],
    ]))->toThrow(InvalidResumeVariantResponseException::class);
});

it('exposes MAX_SELECTED_PROJECTS as 1, the single source of truth the prompt schema also reads', function () {
    expect(ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS)->toBe(1);
});

// --- Experience CareerFact eligibility -----------------------------------
//
// As of ResumeSelectionPromptV3, an Experience bullet group no longer
// declares its own project_id at all — see
// ResumeSelectionResponseValidator's own docblock and
// docs/resume-variant-generation.md "Design boundary: selection vs.
// provenance". Project attribution is now derived deterministically by
// GenerateResumeVariant from each bullet's approved career_fact_keys
// (see GenerateResumeVariantTest.php for those derivation tests) — the
// validator's own remaining responsibility here is: does every cited
// CareerFact belong to the *eligibility set* GenerateResumeVariant
// precomputed for the bullet's declared role_id (Role-direct, that
// Role's Project-direct, or that Role's Employer-direct — see
// GenerateResumeVariant::eligibleFactKeysForRole()'s own docblock).
// CareerProfile-direct facts never enter any role's eligibility set.
// This file exercises the validator's own consumption of an already-
// computed eligibility map in isolation; the map-building logic itself
// (which genuinely needs real Employer/Role/Project relationships —
// Project-direct, Employer-direct-across-multiple-roles,
// CareerProfile-direct exclusion) is exercised end-to-end with real
// fixtures in GenerateResumeVariantTest.php's own "Experience CareerFact
// eligibility" section, including the two exact real regression keys.

/**
 * @param  array<int, string>  $factKeys
 * @return array<string, mixed>
 */
function experienceGroup(int $roleId, array $factKeys, string $titleChoice = 'full'): array
{
    return [
        'summary_evidence' => [],
        'skills' => [],
        'experience' => [[
            'role_id' => $roleId,
            'title_choice' => $titleChoice,
            'bullet_groups' => [[
                'order' => 1,
                'career_fact_keys' => $factKeys,
                'job_analysis_finding_ids' => [],
            ]],
        ]],
        'selected_projects' => [],
        'target_term_usages' => [],
    ];
}

it('accepts a bullet group whose cited facts are eligible for the declared role', function () {
    $response = experienceGroup(roleId: 1, factKeys: ['fact-a', 'fact-b']);

    $validated = validateSelectionResponse($response, [
        'validFactKeys' => ['fact-a', 'fact-b'],
        'eligibleFactKeysForRole' => [1 => ['fact-a' => true, 'fact-b' => true]],
    ]);

    expect($validated['experience'][0]['bullet_groups'][0]['career_fact_keys'])->toBe(['fact-a', 'fact-b']);
});

it('accepts a fact eligible for a role via more than one route at once (e.g. both Role-direct and, hypothetically, Employer-direct)', function () {
    // The eligibility set itself is role-scoped and route-agnostic by
    // the time the validator sees it — it doesn't matter *why* a key is
    // in a role's set, only that it is. GenerateResumeVariantTest.php
    // proves each route (Role/Project/Employer-direct) is populated
    // correctly in the first place.
    $response = experienceGroup(roleId: 1, factKeys: ['fact-a']);

    $validated = validateSelectionResponse($response, [
        'validFactKeys' => ['fact-a'],
        'eligibleFactKeysForRole' => [1 => ['fact-a' => true], 2 => ['fact-a' => true]],
    ]);

    expect($validated['experience'][0]['bullet_groups'][0]['career_fact_keys'])->toBe(['fact-a']);
});

it('rejects a bullet group citing a CareerFact that is not eligible for the declared role at all', function () {
    $response = experienceGroup(roleId: 3, factKeys: ['sibling-role-fact']);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['sibling-role-fact'],
        'eligibleFactKeysForRole' => [2 => ['sibling-role-fact' => true]], // eligible for role 2 only, not role 3
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'is not eligible Experience evidence for role_id [3]');
});

it('rejects a bullet group citing an independent-project CareerFact, which is eligible for no role at all', function () {
    $response = experienceGroup(roleId: 1, factKeys: ['independent-project-fact']);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['independent-project-fact'],
        'eligibleFactKeysForRole' => [1 => []], // no entry at all — an independent-project fact is eligible nowhere
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'is not eligible Experience evidence for role_id [1]');
});

it('rejects a bullet group citing a CareerProfile-direct fact, which is eligible for no Experience role', function () {
    // Mirrors the real TRM Labs regression shape at the unit level —
    // the two exact real regression keys (profile-ai-assisted-
    // development-pattern, profile-github-personal-projects) are proven
    // with real fixtures in GenerateResumeVariantTest.php.
    $response = experienceGroup(roleId: 1, factKeys: ['profile-level-fact']);

    expect(fn () => validateSelectionResponse($response, [
        'validFactKeys' => ['profile-level-fact'],
        'eligibleFactKeysForRole' => [1 => []], // CareerProfile-direct facts never enter any role's set
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'is not eligible Experience evidence for role_id [1]');
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

// --- Duplicate career_fact_key within one bullet group ------------------
//
// Closes the exact gap a real TRM Labs async Resume run exposed: a
// bullet group whose own career_fact_keys array cited the same
// CareerFact twice went undetected by every pre-existing check (which
// only ever compare across bullet groups, never within one array's own
// values) and reached GenerateResumeVariant::generateFull()'s
// persistence transaction, where it failed with a raw
// UniqueConstraintViolationException on
// resume_variant_bullet_citations' (bullet_id, career_fact_id) unique
// constraint — after both Selection and Wording had already run. See
// assertNoDuplicateCareerFactKeysWithinBulletGroup()'s own docblock and
// docs/resume-variant-generation.md "Async Resume".

it('rejects a bullet group whose career_fact_keys array cites the same CareerFact twice', function () {
    $response = experienceGroup(roleId: 1, factKeys: ['fact-a', 'fact-a']);

    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => ['fact-a']]))
        ->toThrow(
            InvalidResumeVariantResponseException::class,
            'career_fact_key [fact-a] is selected more than once within bullet group [0] for role_id [1]'
        );
});

it('rejects the exact real regression shape — the same real CareerFact key duplicated within one bullet group', function () {
    $realKey = 'liquid-gravity-cms-crm-marketing-automation-integrations';
    $response = experienceGroup(roleId: 1, factKeys: [$realKey, $realKey]);

    expect(fn () => validateSelectionResponse($response, ['validFactKeys' => [$realKey]]))
        ->toThrow(InvalidResumeVariantResponseException::class, "career_fact_key [{$realKey}] is selected more than once");
});

it('accepts a bullet group citing two genuinely different CareerFacts', function () {
    $response = experienceGroup(roleId: 1, factKeys: ['fact-a', 'fact-b']);

    $validated = validateSelectionResponse($response, ['validFactKeys' => ['fact-a', 'fact-b']]);

    expect($validated['experience'][0]['bullet_groups'][0]['career_fact_keys'])->toBe(['fact-a', 'fact-b']);
});

it('does not broaden the new invariant across bullet groups — the same CareerFact cited by two different bullet groups (with otherwise distinct evidence) still passes, per pre-existing evidence-reuse semantics', function () {
    $response = selectionResponseWithBulletFacts([
        1 => [
            ['fact-shared', 'fact-a'],
            ['fact-shared', 'fact-b'],
        ],
    ]);

    $validated = validateSelectionResponse($response, ['validFactKeys' => allFactKeysIn($response)]);

    expect($validated['experience'][0]['bullet_groups'][0]['career_fact_keys'])->toBe(['fact-shared', 'fact-a'])
        ->and($validated['experience'][0]['bullet_groups'][1]['career_fact_keys'])->toBe(['fact-shared', 'fact-b']);
});
