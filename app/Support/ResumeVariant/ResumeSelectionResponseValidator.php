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
 * target-term posture authorization, and structural anti-redundancy.
 * Runs before any Eloquent model exists. Mirrors
 * JobMatchResponseValidator. See docs/resume-variant-generation.md.
 */
final class ResumeSelectionResponseValidator
{
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
    ): array {
        $validator = ValidatorFacade::make($structuredContent, $this->rules());

        $validator->after(function (Validator $validator) use (
            $structuredContent, $validRoleIds, $validFactKeys,
            $validSkillIds, $roleIdByProjectId, $validFindingIds,
            $titleChoicesByRole, $directEvidenceExistsByTerm, $resumeEligibleRoleIds,
        ) {
            $experience = is_array($structuredContent['experience'] ?? null) ? $structuredContent['experience'] : [];
            $targetTermUsages = is_array($structuredContent['target_term_usages'] ?? null) ? $structuredContent['target_term_usages'] : [];

            $this->assertReferentialIntegrity($validator, $structuredContent, $validFactKeys, $validSkillIds, $validFindingIds);
            $this->assertNoDuplicateSelections($validator, $structuredContent, $experience);
            $this->assertRoleAndProjectValidity($validator, $experience, $validRoleIds, $roleIdByProjectId, $titleChoicesByRole);
            $this->assertRoleCompleteness($validator, $experience, $resumeEligibleRoleIds);
            $this->assertNoDuplicateBulletGroups($validator, $experience);
            $this->assertTargetTermUsages($validator, $targetTermUsages, $experience, $directEvidenceExistsByTerm);
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
     * @param  array<int, mixed>  $targetTermUsages
     * @param  array<int, mixed>  $experience
     * @param  array<string, bool>  $directEvidenceExistsByTerm
     */
    private function assertTargetTermUsages(
        Validator $validator,
        array $targetTermUsages,
        array $experience,
        array $directEvidenceExistsByTerm,
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

                $groupCount = $resolvedRole !== null && is_array($resolvedRole['bullet_groups'] ?? null)
                    ? count($resolvedRole['bullet_groups'])
                    : 0;

                if ($resolvedRole === null || ! is_int($bulletGroupIndex) || $bulletGroupIndex < 0 || $bulletGroupIndex >= $groupCount) {
                    $validator->errors()->add(
                        "target_term_usages.{$i}.bullet_group_index",
                        "location role_id [{$roleId}] / bullet_group_index [{$bulletGroupIndex}] does not refer to an actually-declared bullet group."
                    );
                }

                $locationKey = "{$roleId}:{$bulletGroupIndex}";
            } else {
                if ($roleId !== -1 || $bulletGroupIndex !== -1) {
                    $validator->errors()->add(
                        "target_term_usages.{$i}.location_type",
                        'A summary-location usage must use the -1/-1 sentinel for role_id and bullet_group_index.'
                    );
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
}
