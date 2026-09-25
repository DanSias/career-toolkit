<?php

namespace App\Support\ResumeVariant;

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Exceptions\InvalidResumeVariantResponseException;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The single authoritative, deterministic check on the Resume Selection
 * provider's decoded response — structure, types, every enum value,
 * referential integrity, title_choice legitimacy per selected role,
 * role/project binding at both the bullet-group level
 * (`assertRoleAndProjectValidity()`) and the individual-CareerFact
 * level (`assertExperienceFactProjectConsistency()`), target-term
 * posture authorization, target-term location/evidence locality
 * (`assertUsageEvidenceIsLocal()`), structural anti-redundancy, and a
 * provider-neutral content-volume budget (MAX_EXPERIENCE_BULLET_GROUPS/
 * MAX_SELECTED_SKILLS — see their own docblocks). Runs before any
 * Eloquent model exists. Mirrors JobMatchResponseValidator. See
 * docs/resume-variant-generation.md.
 */
final class ResumeSelectionResponseValidator
{
    /**
     * Hard content-budget ceilings — deterministic, provider-neutral,
     * independent of anything either provider's prompt says. Chosen
     * from direct inspection of resources/views/resume/print.blade.php
     * (11pt/1.45-line-height, 8.5x11in page, 0.75in margins) against a
     * real, already-persisted Formic ResumeVariant
     * (`resume-selection-v1.5`, `openai:gpt-5.6-terra`) that selected 17
     * Experience bullet groups + 22 Skills and rendered to roughly
     * three pages against a two-page target — see
     * docs/resume-variant-contract.md "Resume Selection" for the full
     * renderer-evidence writeup. Both numbers are the smallest
     * deterministic constraint that would have rejected that exact
     * over-selection outright while remaining generous enough for
     * legitimate tailoring; they are not a page-count guarantee.
     */
    private const MAX_EXPERIENCE_BULLET_GROUPS = 13;

    private const MAX_SELECTED_SKILLS = 18;

    /**
     * @param  array<string, mixed>  $structuredContent  Untrusted, decoded provider output.
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, string>  $validFactKeys
     * @param  array<int, int>  $validSkillIds
     * @param  array<int, int>  $roleIdByProjectId  project_id => the role_id it actually belongs to.
     * @param  array<int, int>  $validFindingIds
     * @param  array<int, array<string, string>>  $titleChoicesByRole  role_id => ['full' => canonical title, 'segment_N' => trimmed "/"-segment, ...]
     * @param  array<string, bool>  $directEvidenceExistsByTerm
     * @param  array<int, int>  $resumeEligibleRoleIds  Every role_id with at least one resume-eligible CareerFact attributed to it directly or via a Project — must each appear in `experience` with at least one bullet group. See assertRoleCompleteness().
     * @param  array<int, int>  $validIndependentProjectIds  Independent (role_id-null) Projects only — never a professional project's id. See assertSelectedProjects().
     * @param  array<string, int>  $projectIdByFactKey  career_fact_key => the project_id it is actually attributed to (only present for project-attributed facts).
     * @return array<string, mixed>
     *
     * @throws InvalidResumeVariantResponseException
     */
    public function validate(
        array $structuredContent,
        array $validRoleIds,
        array $validFactKeys,
        array $validSkillIds,
        array $roleIdByProjectId,
        array $validFindingIds,
        array $titleChoicesByRole,
        array $directEvidenceExistsByTerm,
        array $resumeEligibleRoleIds,
        array $validIndependentProjectIds,
        array $projectIdByFactKey,
    ): array {
        $validator = ValidatorFacade::make($structuredContent, $this->rules());

        $validator->after(function (Validator $validator) use (
            $structuredContent, $validRoleIds, $validFactKeys,
            $validSkillIds, $roleIdByProjectId, $validFindingIds,
            $titleChoicesByRole, $directEvidenceExistsByTerm, $resumeEligibleRoleIds,
            $validIndependentProjectIds, $projectIdByFactKey,
        ) {
            $experience = is_array($structuredContent['experience'] ?? null) ? $structuredContent['experience'] : [];
            $selectedProjects = is_array($structuredContent['selected_projects'] ?? null) ? $structuredContent['selected_projects'] : [];
            $targetTermUsages = is_array($structuredContent['target_term_usages'] ?? null) ? $structuredContent['target_term_usages'] : [];
            $summaryEvidenceFactKeys = is_array($structuredContent['summary_evidence'] ?? null) ? $structuredContent['summary_evidence'] : [];

            $this->assertReferentialIntegrity($validator, $structuredContent, $validFactKeys, $validSkillIds, $validFindingIds);
            $this->assertNoDuplicateSelections($validator, $structuredContent, $experience);
            $this->assertRoleAndProjectValidity($validator, $experience, $validRoleIds, $roleIdByProjectId, $titleChoicesByRole);
            $this->assertRoleCompleteness($validator, $experience, $resumeEligibleRoleIds);
            $this->assertNoDuplicateBulletGroups($validator, $experience);
            $this->assertExperienceFactProjectConsistency($validator, $experience, $projectIdByFactKey);
            $this->assertExperienceBulletBudget($validator, $experience);
            $this->assertSkillsBudget($validator, $structuredContent);
            $this->assertSelectedProjects($validator, $selectedProjects, $validFactKeys, $validIndependentProjectIds, $projectIdByFactKey);
            $this->assertTargetTermUsages($validator, $targetTermUsages, $experience, $directEvidenceExistsByTerm, $summaryEvidenceFactKeys);
        });

        if ($validator->fails()) {
            throw new InvalidResumeVariantResponseException(
                'Resume Selection provider response failed validation: '
                .implode(' ', $validator->errors()->all()),
                context: $structuredContent,
            );
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'summary_evidence' => ['present', 'array'],
            'summary_evidence.*' => ['string'],

            'skills' => ['present', 'array'],
            'skills.*.skill_id' => ['required', 'integer'],
            'skills.*.order' => ['required', 'integer'],

            'experience' => ['present', 'array'],
            'experience.*.role_id' => ['required', 'integer'],
            'experience.*.title_choice' => ['required', 'string'],
            'experience.*.bullet_groups' => ['present', 'array'],
            'experience.*.bullet_groups.*.project_id' => ['required', 'integer'],
            'experience.*.bullet_groups.*.order' => ['required', 'integer'],
            'experience.*.bullet_groups.*.career_fact_keys' => ['present', 'array'],
            'experience.*.bullet_groups.*.career_fact_keys.*' => ['string'],
            'experience.*.bullet_groups.*.job_analysis_finding_ids' => ['present', 'array'],
            'experience.*.bullet_groups.*.job_analysis_finding_ids.*' => ['integer'],

            'selected_projects' => ['present', 'array', 'max:3'],
            'selected_projects.*.project_id' => ['required', 'integer'],
            'selected_projects.*.order' => ['required', 'integer'],
            'selected_projects.*.career_fact_keys' => ['present', 'array'],
            'selected_projects.*.career_fact_keys.*' => ['string'],

            'target_term_usages' => ['present', 'array'],
            'target_term_usages.*.term' => ['required', 'string'],
            'target_term_usages.*.job_analysis_finding_id' => ['required', 'integer'],
            'target_term_usages.*.posture' => ['required', 'string', Rule::enum(ResumeClaimPosture::class)],
            'target_term_usages.*.location_type' => ['required', 'string', Rule::enum(ResumeTermUsageLocation::class)],
            'target_term_usages.*.role_id' => ['required', 'integer'],
            'target_term_usages.*.bullet_group_index' => ['required', 'integer'],
            'target_term_usages.*.relationship_phrase_key' => ['required', 'string', Rule::enum(ResumeQualifiedPhrase::class)],
            'target_term_usages.*.career_fact_keys' => ['present', 'array'],
            'target_term_usages.*.career_fact_keys.*' => ['string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $structuredContent
     * @param  array<int, string>  $validFactKeys
     * @param  array<int, int>  $validSkillIds
     * @param  array<int, int>  $validFindingIds
     */
    private function assertReferentialIntegrity(
        Validator $validator,
        array $structuredContent,
        array $validFactKeys,
        array $validSkillIds,
        array $validFindingIds,
    ): void {
        foreach ((is_array($structuredContent['summary_evidence'] ?? null) ? $structuredContent['summary_evidence'] : []) as $i => $key) {
            if (is_string($key) && ! in_array($key, $validFactKeys, true)) {
                $validator->errors()->add("summary_evidence.{$i}", "career_fact_key [{$key}] was not supplied in the provider input.");
            }
        }

        foreach ((is_array($structuredContent['skills'] ?? null) ? $structuredContent['skills'] : []) as $i => $skill) {
            $id = is_array($skill) ? ($skill['skill_id'] ?? null) : null;
            if (is_int($id) && ! in_array($id, $validSkillIds, true)) {
                $validator->errors()->add("skills.{$i}.skill_id", "skill_id [{$id}] was not supplied in the provider input.");
            }
        }

        foreach ((is_array($structuredContent['selected_projects'] ?? null) ? $structuredContent['selected_projects'] : []) as $i => $item) {
            foreach ((is_array($item) && is_array($item['career_fact_keys'] ?? null) ? $item['career_fact_keys'] : []) as $j => $key) {
                if (is_string($key) && ! in_array($key, $validFactKeys, true)) {
                    $validator->errors()->add("selected_projects.{$i}.career_fact_keys.{$j}", "career_fact_key [{$key}] was not supplied in the provider input.");
                }
            }
        }

        $experience = is_array($structuredContent['experience'] ?? null) ? $structuredContent['experience'] : [];

        foreach ($experience as $roleIndex => $role) {
            if (! is_array($role)) {
                continue;
            }

            $bulletGroups = is_array($role['bullet_groups'] ?? null) ? $role['bullet_groups'] : [];

            foreach ($bulletGroups as $groupIndex => $group) {
                if (! is_array($group)) {
                    continue;
                }

                foreach ((is_array($group['career_fact_keys'] ?? null) ? $group['career_fact_keys'] : []) as $j => $key) {
                    if (is_string($key) && ! in_array($key, $validFactKeys, true)) {
                        $validator->errors()->add(
                            "experience.{$roleIndex}.bullet_groups.{$groupIndex}.career_fact_keys.{$j}",
                            "career_fact_key [{$key}] was not supplied in the provider input."
                        );
                    }
                }

                foreach ((is_array($group['job_analysis_finding_ids'] ?? null) ? $group['job_analysis_finding_ids'] : []) as $j => $id) {
                    if (is_int($id) && ! in_array($id, $validFindingIds, true)) {
                        $validator->errors()->add(
                            "experience.{$roleIndex}.bullet_groups.{$groupIndex}.job_analysis_finding_ids.{$j}",
                            "job_analysis_finding_id [{$id}] was not supplied in the provider input."
                        );
                    }
                }

                if ((is_array($group['career_fact_keys'] ?? null) ? count($group['career_fact_keys']) : 0) === 0) {
                    $validator->errors()->add(
                        "experience.{$roleIndex}.bullet_groups.{$groupIndex}.career_fact_keys",
                        'A bullet group must cite at least one CareerFact.'
                    );
                }
            }
        }

        $targetTermUsages = is_array($structuredContent['target_term_usages'] ?? null) ? $structuredContent['target_term_usages'] : [];

        foreach ($targetTermUsages as $i => $usage) {
            if (! is_array($usage)) {
                continue;
            }

            $findingId = $usage['job_analysis_finding_id'] ?? null;
            if (is_int($findingId) && ! in_array($findingId, $validFindingIds, true)) {
                $validator->errors()->add("target_term_usages.{$i}.job_analysis_finding_id", "job_analysis_finding_id [{$findingId}] was not supplied in the provider input.");
            }

            $keys = is_array($usage['career_fact_keys'] ?? null) ? $usage['career_fact_keys'] : [];

            if (count($keys) === 0) {
                $validator->errors()->add("target_term_usages.{$i}.career_fact_keys", 'A target-term usage must cite at least one CareerFact.');
            }

            foreach ($keys as $j => $key) {
                if (is_string($key) && ! in_array($key, $validFactKeys, true)) {
                    $validator->errors()->add("target_term_usages.{$i}.career_fact_keys.{$j}", "career_fact_key [{$key}] was not supplied in the provider input.");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $structuredContent
     * @param  array<int, mixed>  $experience
     */
    private function assertNoDuplicateSelections(Validator $validator, array $structuredContent, array $experience): void
    {
        $skillIds = [];
        foreach ((is_array($structuredContent['skills'] ?? null) ? $structuredContent['skills'] : []) as $i => $skill) {
            $id = is_array($skill) ? ($skill['skill_id'] ?? null) : null;
            if (is_int($id)) {
                if (in_array($id, $skillIds, true)) {
                    $validator->errors()->add("skills.{$i}.skill_id", "skill_id [{$id}] is selected more than once.");
                }
                $skillIds[] = $id;
            }
        }

        $roleIds = [];
        foreach ($experience as $i => $role) {
            $id = is_array($role) ? ($role['role_id'] ?? null) : null;
            if (is_int($id)) {
                if (in_array($id, $roleIds, true)) {
                    $validator->errors()->add("experience.{$i}.role_id", "role_id [{$id}] appears more than once.");
                }
                $roleIds[] = $id;
            }
        }

        $selectedProjectIds = [];
        foreach ((is_array($structuredContent['selected_projects'] ?? null) ? $structuredContent['selected_projects'] : []) as $i => $item) {
            $id = is_array($item) ? ($item['project_id'] ?? null) : null;
            if (is_int($id)) {
                if (in_array($id, $selectedProjectIds, true)) {
                    $validator->errors()->add("selected_projects.{$i}.project_id", "project_id [{$id}] is selected more than once.");
                }
                $selectedProjectIds[] = $id;
            }
        }
    }

    /**
     * @param  array<int, mixed>  $experience
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, int>  $roleIdByProjectId
     * @param  array<int, array<string, string>>  $titleChoicesByRole
     */
    private function assertRoleAndProjectValidity(
        Validator $validator,
        array $experience,
        array $validRoleIds,
        array $roleIdByProjectId,
        array $titleChoicesByRole,
    ): void {
        foreach ($experience as $roleIndex => $role) {
            if (! is_array($role)) {
                continue;
            }

            $roleId = $role['role_id'] ?? null;

            if (is_int($roleId) && ! in_array($roleId, $validRoleIds, true)) {
                $validator->errors()->add("experience.{$roleIndex}.role_id", "role_id [{$roleId}] was not supplied in the provider input.");

                continue;
            }

            // A closed choice key, never model-written title text — the
            // only check needed is whether this exact role legally
            // offers this key at all. No fuzzy matching, no fallback to
            // 'full' for an unsupported choice.
            $titleChoice = $role['title_choice'] ?? null;
            $allowedChoices = is_int($roleId) ? ($titleChoicesByRole[$roleId] ?? []) : [];

            if (is_string($titleChoice) && ! array_key_exists($titleChoice, $allowedChoices)) {
                $validator->errors()->add(
                    "experience.{$roleIndex}.title_choice",
                    "title_choice [{$titleChoice}] is not a legal title representation for role_id [{$roleId}]."
                );
            }

            $bulletGroups = is_array($role['bullet_groups'] ?? null) ? $role['bullet_groups'] : [];

            foreach ($bulletGroups as $groupIndex => $group) {
                if (! is_array($group)) {
                    continue;
                }

                $projectId = $group['project_id'] ?? null;

                // -1 is the "no project" sentinel — always valid.
                if (is_int($projectId) && $projectId !== -1 && ($roleIdByProjectId[$projectId] ?? null) !== $roleId) {
                    $validator->errors()->add(
                        "experience.{$roleIndex}.bullet_groups.{$groupIndex}.project_id",
                        "project_id [{$projectId}] does not belong to role_id [{$roleId}]."
                    );
                }
            }
        }
    }

    /**
     * A bullet group's non-`-1` `project_id` states that bullet's
     * entire factual scope is that one project — confirmed against the
     * two real, already-persisted, human-accepted Formic selections
     * (`resume-selection-v1.5`, `openai:gpt-5.6-terra`): every
     * project-specific bullet group in that historical data cites only
     * facts truly attributed to that exact project, and every
     * role-level (unattributed) fact is instead grouped under its own
     * `-1` bullet, never mixed into a project-specific one. Mirrors
     * `assertSelectedProjects()`'s identical rule for Selected
     * Projects, extended here to Experience bullet groups for the
     * first time. A role-level (`project_id === null` for the fact)
     * CareerFact cited under a non-`-1` bullet group is therefore
     * rejected exactly the same as a sibling-project fact — the
     * bullet's declared project is a hard claim about every cited
     * fact's scope, not merely about which role it belongs to.
     *
     * `-1` ("no specific project") bullet groups are deliberately NOT
     * constrained this way — nothing in the schema, prompt, or
     * historical data requires it, and a role-level claim can
     * legitimately rest on evidence from more than one project, or
     * none at all.
     *
     * This is the deterministic backstop for the exact failure mode a
     * live qwen3.8:27b evaluation produced: a bullet group declared
     * for one project citing a CareerFact truly attributed to a
     * sibling project (RocketGate Transaction Toolkit tagged as
     * Verbatim; Pearson Email Marketing Tracker tagged as Marketing
     * Forecast; Pearson Recruitment Agent Tracking tagged as
     * Salesforce Migration) — distinct from, and in addition to, the
     * cross-*role* case `assertRoleAndProjectValidity()` above already
     * catches.
     *
     * @param  array<int, mixed>  $experience
     * @param  array<string, int>  $projectIdByFactKey
     */
    private function assertExperienceFactProjectConsistency(Validator $validator, array $experience, array $projectIdByFactKey): void
    {
        foreach ($experience as $roleIndex => $role) {
            if (! is_array($role)) {
                continue;
            }

            $bulletGroups = is_array($role['bullet_groups'] ?? null) ? $role['bullet_groups'] : [];

            foreach ($bulletGroups as $groupIndex => $group) {
                if (! is_array($group)) {
                    continue;
                }

                $projectId = $group['project_id'] ?? null;

                if (! is_int($projectId) || $projectId === -1) {
                    continue;
                }

                $keys = is_array($group['career_fact_keys'] ?? null) ? $group['career_fact_keys'] : [];

                foreach ($keys as $keyIndex => $key) {
                    if (! is_string($key)) {
                        continue;
                    }

                    $factProjectId = $projectIdByFactKey[$key] ?? null;

                    if ($factProjectId !== $projectId) {
                        $validator->errors()->add(
                            "experience.{$roleIndex}.bullet_groups.{$groupIndex}.career_fact_keys.{$keyIndex}",
                            "career_fact_key [{$key}] is not attributed to project_id [{$projectId}] declared for this bullet group."
                        );
                    }
                }
            }
        }
    }

    /**
     * Relevance controls emphasis, never basic employment-history
     * presence: every resume-eligible role (one with at least one
     * resume-eligible CareerFact attributed to it or to one of its
     * projects) must appear in `experience` with at least one bullet
     * group, even if that group is a single, modest bullet. A response
     * that silently drops a resume-eligible role is rejected outright
     * rather than accepted with a gap in the candidate's history.
     *
     * @param  array<int, mixed>  $experience
     * @param  array<int, int>  $resumeEligibleRoleIds
     */
    private function assertRoleCompleteness(Validator $validator, array $experience, array $resumeEligibleRoleIds): void
    {
        $representedRoleIds = [];

        foreach ($experience as $role) {
            if (! is_array($role)) {
                continue;
            }

            $roleId = $role['role_id'] ?? null;
            $bulletGroups = is_array($role['bullet_groups'] ?? null) ? $role['bullet_groups'] : [];

            if (is_int($roleId) && count($bulletGroups) > 0) {
                $representedRoleIds[] = $roleId;
            }
        }

        $missingRoleIds = array_values(array_diff($resumeEligibleRoleIds, $representedRoleIds));

        if ($missingRoleIds !== []) {
            $validator->errors()->add(
                'experience',
                'Response is missing required representation for resume-eligible role(s): '
                .implode(', ', $missingRoleIds)
                .' — every resume-eligible Role must appear with at least one bullet group.'
            );
        }
    }

    /**
     * Every selected_projects entry must be: a real, independent
     * (role_id-null) Project belonging to this profile — never a
     * professional one, structurally impossible via the schema's own
     * enum, but re-checked here in PHP anyway (never trust the schema
     * alone — the same doctrine as every other id check in this
     * class); non-empty on evidence; and every cited CareerFact must
     * actually be attributed to that exact Project, not merely
     * eligible in general. See docs/domain-model.md "ResumeVariant" ->
     * "Selected Projects".
     *
     * @param  array<int, mixed>  $selectedProjects
     * @param  array<int, string>  $validFactKeys
     * @param  array<int, int>  $validIndependentProjectIds
     * @param  array<string, int>  $projectIdByFactKey
     */
    private function assertSelectedProjects(
        Validator $validator,
        array $selectedProjects,
        array $validFactKeys,
        array $validIndependentProjectIds,
        array $projectIdByFactKey,
    ): void {
        if (count($selectedProjects) > 3) {
            $validator->errors()->add('selected_projects', 'At most 3 Selected Projects are allowed.');
        }

        foreach ($selectedProjects as $i => $item) {
            if (! is_array($item)) {
                continue;
            }

            $projectId = $item['project_id'] ?? null;

            if (is_int($projectId) && ! in_array($projectId, $validIndependentProjectIds, true)) {
                $validator->errors()->add(
                    "selected_projects.{$i}.project_id",
                    "project_id [{$projectId}] is not a valid independent Project for Selected Projects."
                );
            }

            $keys = is_array($item['career_fact_keys'] ?? null) ? $item['career_fact_keys'] : [];

            if (count($keys) === 0) {
                $validator->errors()->add("selected_projects.{$i}.career_fact_keys", 'A selected Project must cite at least one CareerFact.');
            }

            foreach ($keys as $j => $key) {
                if (! is_string($key) || ! in_array($key, $validFactKeys, true)) {
                    continue; // Already reported by assertReferentialIntegrity().
                }

                $factProjectId = $projectIdByFactKey[$key] ?? null;

                if ($factProjectId !== $projectId) {
                    $validator->errors()->add(
                        "selected_projects.{$i}.career_fact_keys.{$j}",
                        "career_fact_key [{$key}] is not attributed to project_id [{$projectId}]."
                    );
                }
            }
        }
    }

    /**
     * The problem to avoid is redundant resume content, not evidence
     * reuse — a CareerFact may legitimately back several distinct
     * claims (see docs/domain-model.md "ResumeVariant"). Only an exact
     * duplicate bullet-group evidence set (the same set of
     * career_fact_keys, order-independent) is rejected structurally.
     *
     * @param  array<int, mixed>  $experience
     */
    private function assertNoDuplicateBulletGroups(Validator $validator, array $experience): void
    {
        $seenSets = [];

        foreach ($experience as $roleIndex => $role) {
            if (! is_array($role)) {
                continue;
            }

            $bulletGroups = is_array($role['bullet_groups'] ?? null) ? $role['bullet_groups'] : [];

            foreach ($bulletGroups as $groupIndex => $group) {
                $keys = is_array($group['career_fact_keys'] ?? null) ? $group['career_fact_keys'] : [];

                if ($keys === []) {
                    continue;
                }

                sort($keys);
                $signature = implode('|', $keys);

                if (in_array($signature, $seenSets, true)) {
                    $validator->errors()->add(
                        "experience.{$roleIndex}.bullet_groups.{$groupIndex}",
                        'This bullet group cites the exact same set of CareerFacts as another bullet group already selected.'
                    );
                }

                $seenSets[] = $signature;
            }
        }
    }

    /**
     * The deterministic replacement for asking the model to infer
     * appropriate resume length from prose — see
     * MAX_EXPERIENCE_BULLET_GROUPS's own docblock for the renderer
     * evidence behind this exact number. Counts every bullet group
     * across every role combined; distribution across roles is
     * irrelevant to this check (`assertRoleCompleteness` above already
     * guarantees every eligible role has at least one). Selected
     * Projects' single bullet each is deliberately NOT counted here —
     * that section is already independently bounded to at most 3
     * entries by `assertSelectedProjects`, and renders far more
     * compactly per entry than an Experience bullet group (see
     * docs/resume-variant-contract.md "Resume Selection").
     *
     * @param  array<int, mixed>  $experience
     */
    private function assertExperienceBulletBudget(Validator $validator, array $experience): void
    {
        $total = 0;

        foreach ($experience as $role) {
            if (! is_array($role)) {
                continue;
            }

            $total += is_array($role['bullet_groups'] ?? null) ? count($role['bullet_groups']) : 0;
        }

        if ($total > self::MAX_EXPERIENCE_BULLET_GROUPS) {
            $validator->errors()->add(
                'experience',
                "Response selects {$total} Experience bullet groups in total, exceeding the hard maximum of ".self::MAX_EXPERIENCE_BULLET_GROUPS
                .' — reduce to the strongest, most differentiated evidence rather than every applicable fact.'
            );
        }
    }

    /**
     * See MAX_SELECTED_SKILLS's own docblock for the renderer evidence
     * behind this exact number.
     *
     * @param  array<string, mixed>  $structuredContent
     */
    private function assertSkillsBudget(Validator $validator, array $structuredContent): void
    {
        $skills = is_array($structuredContent['skills'] ?? null) ? $structuredContent['skills'] : [];
        $total = count($skills);

        if ($total > self::MAX_SELECTED_SKILLS) {
            $validator->errors()->add(
                'skills',
                "Response selects {$total} Skills, exceeding the hard maximum of ".self::MAX_SELECTED_SKILLS
                .' — select the strongest, most relevant subset rather than every eligible Skill.'
            );
        }
    }

    /**
     * @param  array<int, mixed>  $targetTermUsages
     * @param  array<int, mixed>  $experience
     * @param  array<string, bool>  $directEvidenceExistsByTerm
     * @param  array<int, string>  $summaryEvidenceFactKeys  The exact CareerFact keys Selection assigned to the Summary — the only evidence a summary-location usage may cite.
     */
    private function assertTargetTermUsages(
        Validator $validator,
        array $targetTermUsages,
        array $experience,
        array $directEvidenceExistsByTerm,
        array $summaryEvidenceFactKeys,
    ): void {
        // location key ("summary" or "role_id:index") => count of qualified usages there.
        $qualifiedCountByLocation = [];

        foreach ($targetTermUsages as $i => $usage) {
            if (! is_array($usage)) {
                continue;
            }

            $term = $usage['term'] ?? null;
            $posture = $usage['posture'] ?? null;
            $locationType = $usage['location_type'] ?? null;
            $roleId = $usage['role_id'] ?? null;
            $bulletGroupIndex = $usage['bullet_group_index'] ?? null;
            $phraseKey = $usage['relationship_phrase_key'] ?? null;

            // `direct` may only be declared where evidence actually
            // authorizes it — computed independently, never trusted
            // from the model's own say-so.
            if ($posture === ResumeClaimPosture::Direct->value && is_string($term)) {
                if (! ($directEvidenceExistsByTerm[$term] ?? false)) {
                    $validator->errors()->add(
                        "target_term_usages.{$i}.posture",
                        "term [{$term}] is not authorized for a direct claim — no direct, attributed canonical evidence exists for it."
                    );
                }
            }

            // relationship_phrase_key must be not_applicable except for
            // qualified, and must be a real phrase for qualified.
            $isQualified = $posture === ResumeClaimPosture::Qualified->value;
            $isNotApplicablePhrase = $phraseKey === ResumeQualifiedPhrase::NotApplicable->value;

            if ($isQualified && $isNotApplicablePhrase) {
                $validator->errors()->add("target_term_usages.{$i}.relationship_phrase_key", 'A qualified usage must supply a real relationship phrase, not not_applicable.');
            }

            if (! $isQualified && ! $isNotApplicablePhrase) {
                $validator->errors()->add("target_term_usages.{$i}.relationship_phrase_key", 'relationship_phrase_key must be not_applicable when posture is not qualified.');
            }

            // Location consistency: bullet needs a real role_id/index
            // pointing at an actually-declared bullet group; summary
            // needs both sentinels.
            if ($locationType === ResumeTermUsageLocation::Bullet->value) {
                $resolvedRole = null;

                foreach ($experience as $role) {
                    if (is_array($role) && ($role['role_id'] ?? null) === $roleId) {
                        $resolvedRole = $role;

                        break;
                    }
                }

                $bulletGroups = $resolvedRole !== null && is_array($resolvedRole['bullet_groups'] ?? null)
                    ? $resolvedRole['bullet_groups']
                    : [];
                $groupCount = count($bulletGroups);

                if ($resolvedRole === null || ! is_int($bulletGroupIndex) || $bulletGroupIndex < 0 || $bulletGroupIndex >= $groupCount) {
                    $validator->errors()->add(
                        "target_term_usages.{$i}.bullet_group_index",
                        "location role_id [{$roleId}] / bullet_group_index [{$bulletGroupIndex}] does not refer to an actually-declared bullet group."
                    );
                } else {
                    $resolvedGroup = $bulletGroups[$bulletGroupIndex];
                    $locationFactKeys = is_array($resolvedGroup['career_fact_keys'] ?? null) ? $resolvedGroup['career_fact_keys'] : [];

                    $this->assertUsageEvidenceIsLocal(
                        $validator, $i, $usage, $locationFactKeys,
                        "role_id [{$roleId}] bullet_group_index [{$bulletGroupIndex}]"
                    );
                }

                $locationKey = "{$roleId}:{$bulletGroupIndex}";
            } else {
                if ($roleId !== -1 || $bulletGroupIndex !== -1) {
                    $validator->errors()->add(
                        "target_term_usages.{$i}.location_type",
                        'A summary-location usage must use the -1/-1 sentinel for role_id and bullet_group_index.'
                    );
                } else {
                    $this->assertUsageEvidenceIsLocal($validator, $i, $usage, $summaryEvidenceFactKeys, 'the Summary');
                }

                $locationKey = 'summary';
            }

            if ($isQualified) {
                $qualifiedCountByLocation[$locationKey] = ($qualifiedCountByLocation[$locationKey] ?? 0) + 1;
            }
        }

        foreach ($qualifiedCountByLocation as $location => $count) {
            if ($count > 1) {
                $validator->errors()->add(
                    'target_term_usages',
                    "location [{$location}] has {$count} qualified target-term usages — at most one qualified usage is allowed per bullet/summary."
                );
            }
        }
    }

    /**
     * A target-term usage's own `career_fact_keys` must be evidence
     * already declared at its own `location` — never borrowed from a
     * sibling bullet group in the same role, a different role, a
     * Selected Project, the Summary (for a bullet usage), or a bullet
     * (for a summary usage). This is deliberately stricter than
     * `assertReferentialIntegrity()`'s own check, which only confirms a
     * key exists *somewhere* in the supplied corpus — that check stays
     * unchanged and still runs independently; this one additionally
     * requires the key to belong to the exact location this usage
     * claims.
     *
     * Added after a real, already-persisted Formic `ResumeVariant`
     * (`resume-selection-v1.5`) was found, on inspection, to have done
     * exactly this: a `target_term_usages` entry located at one bullet
     * group while citing a CareerFact that actually belonged to a
     * different bullet group entirely. Nothing in the schema or prior
     * validator caught it, because location referential integrity
     * (does this role_id/bullet_group_index exist) and evidence
     * referential integrity (does this career_fact_key exist anywhere)
     * were checked independently, never against each other. That row
     * is left as historically invalid under this new rule — this
     * validator governs future generation only. See
     * docs/resume-variant-generation.md "Target-term location
     * integrity".
     *
     * Silently skipped when the location itself failed to resolve (an
     * unknown role_id/bullet_group_index, or a malformed summary
     * sentinel) — that failure is already reported by the caller, and
     * there is no valid location evidence set to compare against.
     *
     * @param  array<string, mixed>  $usage
     * @param  array<int, string>  $locationFactKeys  The exact CareerFact keys already declared at this usage's own location.
     */
    private function assertUsageEvidenceIsLocal(Validator $validator, int|string $index, array $usage, array $locationFactKeys, string $locationDescription): void
    {
        $keys = is_array($usage['career_fact_keys'] ?? null) ? $usage['career_fact_keys'] : [];

        foreach ($keys as $j => $key) {
            if (is_string($key) && ! in_array($key, $locationFactKeys, true)) {
                $validator->errors()->add(
                    "target_term_usages.{$index}.career_fact_keys.{$j}",
                    "career_fact_key [{$key}] is not part of the evidence already declared at {$locationDescription} — a target-term usage may only cite evidence belonging to its own declared location."
                );
            }
        }
    }
}
