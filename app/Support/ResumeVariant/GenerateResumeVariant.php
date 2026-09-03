<?php

namespace App\Support\ResumeVariant;

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Models\CareerFact;
use App\Models\JobMatch;
use App\Models\ResumeVariant;
use App\Models\Role;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV1;
use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV1;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The whole resume-generation pipeline for one JobMatch, start to
 * finish:
 *
 *   deterministic payloads -> Selection provider -> validate ->
 *   trusted selection draft -> Wording provider (bounded to the
 *   approved selection) -> validate -> trusted wording draft -> one
 *   atomic transaction creating ResumeVariant + its full relational
 *   tree.
 *
 * Everything before the transaction can fail loudly without touching
 * the database at all. Mirrors App\Support\JobMatch\GenerateJobMatch's
 * structure exactly, extended to two provider calls instead of one.
 * v1 implements full generation only — regenerateWording() (reusing an
 * existing ResumeVariant's approved selection to reword without
 * re-selecting) is deliberately deferred until this base path is
 * proven. See docs/resume-variant-generation.md.
 */
final class GenerateResumeVariant
{
    public function __construct(
        private readonly GeneratesResumeSelection $selectionProvider,
        private readonly GeneratesResumeWording $wordingProvider,
        private readonly ResumeSelectionPromptV1 $selectionPrompt,
        private readonly ResumeWordingPromptV1 $wordingPrompt,
        private readonly ResumeCandidatePayloadBuilder $candidateBuilder,
        private readonly JobPayloadBuilder $jobPayloadBuilder,
        private readonly TargetTerminologyBuilder $targetTerminologyBuilder,
        private readonly ResumeSelectionResponseValidator $selectionValidator,
        private readonly ResumeWordingResponseValidator $wordingValidator,
    ) {}

    public function generateFull(JobMatch $jobMatch): ResumeVariant
    {
        $profile = $jobMatch->careerProfile;

        // ---- Stage 0: deterministic payload construction ----
        $candidatePayload = $this->candidateBuilder->build($jobMatch);
        $jobPayload = $this->jobPayloadBuilder->build($jobMatch->jobAnalysis);
        $targetTerminology = $this->targetTerminologyBuilder->build($jobMatch);

        $validFactKeys = array_column($candidatePayload['career_facts'], 'key');
        $validEducationIds = array_column($candidatePayload['education'], 'id');
        $validSkillIds = array_column($candidatePayload['eligible_skills'], 'id');
        $validFindingIds = array_column($jobPayload['findings'], 'id');
        $validTargetTerms = array_column($targetTerminology, 'term');
        $directEvidenceExistsByTerm = array_combine($validTargetTerms, array_column($targetTerminology, 'direct_evidence_exists'));

        $roles = Role::query()
            ->whereHas('employer', fn ($query) => $query->where('career_profile_id', $profile->id))
            ->with('projects')
            ->get();

        $validRoleIds = $roles->pluck('id')->all();
        $validProjectIds = $roles->flatMap(fn (Role $role) => $role->projects)->pluck('id')->all();

        // Built with an explicit loop, not flatMap/collapse: Collection::
        // collapse() merges sub-arrays with array_merge(), which
        // renumbers integer keys — it would silently destroy this
        // project_id => role_id mapping.
        $roleIdByProjectId = [];
        foreach ($roles as $role) {
            foreach ($role->projects as $project) {
                $roleIdByProjectId[$project->id] = $role->id;
            }
        }

        $validTitleSegmentsByRole = $roles->mapWithKeys(
            fn (Role $role) => [(string) $role->id => $this->titleSegments($role->title)]
        )->all();

        // ---- Stage 1: Selection ----
        $selectionSchema = $this->selectionPrompt->jsonSchema(
            $validRoleIds,
            $validProjectIds,
            $validFactKeys,
            $validEducationIds,
            $validSkillIds,
            $validFindingIds,
            $validTargetTerms,
        );

        $selectionResponse = $this->selectionProvider->generate(
            $this->selectionPrompt->systemPrompt(),
            $this->selectionPrompt->userPrompt($candidatePayload, $jobPayload, $targetTerminology),
            $selectionSchema,
        );

        $validatedSelection = $this->selectionValidator->validate(
            $selectionResponse->structuredContent,
            $validRoleIds,
            $validFactKeys,
            $validEducationIds,
            $validSkillIds,
            $roleIdByProjectId,
            $validFindingIds,
            $validTitleSegmentsByRole,
            $directEvidenceExistsByTerm,
        );

        $selectionDraft = $this->toSelectionDraft($validatedSelection);

        // ---- Build Stage 2 input from exactly what Selection approved ----
        $factsByKey = collect($candidatePayload['career_facts'])->keyBy('key');
        $guardrailByFactKey = $factsByKey->map(fn (array $fact) => $fact['metric']['guardrail'] ?? null)->all();

        $wordingInput = $this->buildWordingInput($selectionDraft, $factsByKey);
        $careerFactKeysByLocation = $this->buildCareerFactKeysByLocation($selectionDraft);
        $denylistTerms = $this->buildDenylistTerms($validTargetTerms, $selectionDraft);

        $rolesNeedingWording = collect($selectionDraft->experience)->filter(fn (RoleSelectionDraft $role) => $role->bulletGroups !== []);
        $rolesInSelection = $rolesNeedingWording->pluck('roleId')->all();
        $expectedBulletGroupIndexesByRole = $rolesNeedingWording->mapWithKeys(
            fn (RoleSelectionDraft $role) => [$role->roleId => array_keys($role->bulletGroups)]
        )->all();

        // ---- Stage 2: Wording ----
        $wordingSchema = $this->wordingPrompt->jsonSchema($rolesInSelection);

        $wordingResponse = $this->wordingProvider->generate(
            $this->wordingPrompt->systemPrompt(),
            $this->wordingPrompt->userPrompt($wordingInput, $denylistTerms),
            $wordingSchema,
        );

        $validatedWording = $this->wordingValidator->validate(
            $wordingResponse->structuredContent,
            $rolesInSelection,
            $expectedBulletGroupIndexesByRole,
            $denylistTerms,
            $careerFactKeysByLocation,
            $guardrailByFactKey,
        );

        $wordingDraft = $this->toWordingDraft($validatedWording);

        // ---- Persist ----
        return DB::transaction(function () use (
            $profile, $jobMatch, $selectionDraft, $wordingDraft,
            $candidatePayload, $jobPayload, $targetTerminology,
            $selectionResponse, $wordingResponse,
            $validatedSelection, $validatedWording, $wordingInput,
        ) {
            $factIdsByKey = CareerFact::query()
                ->where('career_profile_id', $profile->id)
                ->pluck('id', 'key')
                ->all();

            $rolesById = Role::query()
                ->whereIn('id', collect($selectionDraft->experience)->pluck('roleId'))
                ->get()
                ->keyBy('id');

            $summaryQualified = collect($selectionDraft->targetTermUsages)->first(
                fn (TargetTermUsageDraft $usage) => $usage->location === ResumeTermUsageLocation::Summary
                    && $usage->posture === ResumeClaimPosture::Qualified
            );

            $finalSummary = $summaryQualified === null
                ? $wordingDraft->summary
                : $this->appendQualifiedClause($wordingDraft->summary, $summaryQualified->relationshipPhraseKey, $summaryQualified->term);

            $variant = ResumeVariant::create([
                'career_profile_id' => $profile->id,
                'job_match_id' => $jobMatch->id,
                'schema_version' => $this->selectionPrompt->schemaVersion(),
                'selection_prompt_version' => $this->selectionPrompt->version(),
                'wording_prompt_version' => $this->wordingPrompt->version(),
                'selection_generated_by' => $selectionResponse->generatedBy(),
                'wording_generated_by' => $wordingResponse->generatedBy(),
                'generated_at' => now(),
                'selection_input_snapshot' => [
                    'candidate' => $candidatePayload,
                    'job' => $jobPayload,
                    'target_terminology' => $targetTerminology,
                ],
                'selection_raw_response' => $validatedSelection,
                'wording_input_snapshot' => $wordingInput,
                'wording_raw_response' => $validatedWording,
                'summary' => $finalSummary,
            ]);

            $bulletIdsByLocation = [];

            foreach ($selectionDraft->experience as $role) {
                $roleModel = $rolesById->get($role->roleId);
                $wordingRole = collect($wordingDraft->experience)->first(fn (RoleWordingDraft $r) => $r->roleId === $role->roleId);

                foreach ($role->bulletGroups as $index => $group) {
                    $wordingBullet = $wordingRole === null ? null : collect($wordingRole->bullets)
                        ->first(fn (BulletWordingDraft $b) => $b->bulletGroupIndex === $index);

                    $bulletQualified = collect($selectionDraft->targetTermUsages)->first(
                        fn (TargetTermUsageDraft $usage) => $usage->location === ResumeTermUsageLocation::Bullet
                            && $usage->roleId === $role->roleId
                            && $usage->bulletGroupIndex === $index
                            && $usage->posture === ResumeClaimPosture::Qualified
                    );

                    // Wording's own completeness check (assertCompleteness
                    // in ResumeWordingResponseValidator) already guarantees
                    // a matching bullet exists for every approved bullet
                    // group by the time persistence runs — trusted, not
                    // re-guarded here, mirroring how GenerateJobMatch
                    // trusts validated data during its own persist step.
                    $baseText = $wordingBullet->text;
                    $finalText = $bulletQualified === null
                        ? $baseText
                        : $this->appendQualifiedClause($baseText, $bulletQualified->relationshipPhraseKey, $bulletQualified->term);

                    $bullet = $variant->experienceBullets()->create([
                        'employer_id' => $roleModel->employer_id,
                        'role_id' => $role->roleId,
                        'project_id' => $group->projectId,
                        'display_title' => $role->displayTitle,
                        'display_order' => $group->order,
                        'text' => $finalText,
                    ]);

                    $bulletIdsByLocation["{$role->roleId}:{$index}"] = $bullet->id;

                    foreach ($group->careerFactKeys as $key) {
                        $bullet->citations()->create(['career_fact_id' => $factIdsByKey[$key]]);
                    }
                }
            }

            foreach ($selectionDraft->summaryEvidenceFactKeys as $key) {
                $variant->summaryEvidence()->create(['career_fact_id' => $factIdsByKey[$key]]);
            }

            foreach ($selectionDraft->skills as $skill) {
                $variant->skillSelections()->create(['skill_id' => $skill->skillId, 'display_order' => $skill->order]);
            }

            foreach ($selectionDraft->educationSelections as $education) {
                $variant->educationSelections()->create(['education_id' => $education->educationId, 'display_order' => $education->order]);
            }

            foreach ($selectionDraft->targetTermUsages as $usage) {
                $bulletId = $usage->location === ResumeTermUsageLocation::Bullet
                    ? ($bulletIdsByLocation["{$usage->roleId}:{$usage->bulletGroupIndex}"] ?? null)
                    : null;

                $usageRow = $variant->targetTermUsages()->create([
                    'bullet_id' => $bulletId,
                    'job_analysis_finding_id' => $usage->jobAnalysisFindingId,
                    'target_term' => $usage->term,
                    'posture' => $usage->posture,
                    'location' => $usage->location,
                    'relationship_phrase_key' => $usage->relationshipPhraseKey,
                ]);

                foreach ($usage->careerFactKeys as $key) {
                    $usageRow->evidence()->create(['career_fact_id' => $factIdsByKey[$key]]);
                }
            }

            return $variant;
        });
    }

    /**
     * @return array<int, string>
     */
    private function titleSegments(string $title): array
    {
        $segments = array_map('trim', explode('/', $title));

        return array_values(array_unique([$title, ...$segments]));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function toSelectionDraft(array $validated): ResumeSelectionDraft
    {
        /** @var array<int, string> $summaryEvidence */
        $summaryEvidence = $validated['summary_evidence'];
        /** @var array<int, array<string, mixed>> $skills */
        $skills = $validated['skills'];
        /** @var array<int, array<string, mixed>> $educationSelection */
        $educationSelection = $validated['education_selection'];
        /** @var array<int, array<string, mixed>> $experience */
        $experience = $validated['experience'];
        /** @var array<int, array<string, mixed>> $targetTermUsages */
        $targetTermUsages = $validated['target_term_usages'];

        return new ResumeSelectionDraft(
            summaryEvidenceFactKeys: $summaryEvidence,
            skills: array_map(fn (array $s) => new SkillSelectionDraft($s['skill_id'], $s['order']), $skills),
            educationSelections: array_map(fn (array $e) => new EducationSelectionDraft($e['education_id'], $e['order']), $educationSelection),
            experience: array_map(fn (array $r) => new RoleSelectionDraft(
                roleId: $r['role_id'],
                displayTitle: $r['display_title'],
                bulletGroups: array_map(fn (array $g) => new BulletGroupDraft(
                    projectId: $g['project_id'] === -1 ? null : $g['project_id'],
                    order: $g['order'],
                    careerFactKeys: $g['career_fact_keys'],
                    jobAnalysisFindingIds: $g['job_analysis_finding_ids'],
                ), $r['bullet_groups']),
            ), $experience),
            targetTermUsages: array_map(fn (array $u) => new TargetTermUsageDraft(
                term: $u['term'],
                jobAnalysisFindingId: $u['job_analysis_finding_id'],
                posture: ResumeClaimPosture::from($u['posture']),
                location: ResumeTermUsageLocation::from($u['location_type']),
                roleId: $u['role_id'] === -1 ? null : $u['role_id'],
                bulletGroupIndex: $u['bullet_group_index'] === -1 ? null : $u['bullet_group_index'],
                relationshipPhraseKey: ResumeQualifiedPhrase::from($u['relationship_phrase_key']),
                careerFactKeys: $u['career_fact_keys'],
            ), $targetTermUsages),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function toWordingDraft(array $validated): ResumeWordingDraft
    {
        /** @var array<int, array<string, mixed>> $experience */
        $experience = $validated['experience'];

        return new ResumeWordingDraft(
            summary: $validated['summary'],
            experience: array_map(fn (array $r) => new RoleWordingDraft(
                roleId: $r['role_id'],
                bullets: array_map(fn (array $b) => new BulletWordingDraft($b['bullet_group_index'], $b['text']), $r['bullets']),
            ), $experience),
        );
    }

    /**
     * Re-hydrates Stage 1's fact-key references with their real
     * canonical text for Stage 2's prompt — Stage 2 never sees raw
     * keys it would need to look anything up from.
     *
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     * @return array<string, mixed>
     */
    private function buildWordingInput(ResumeSelectionDraft $draft, Collection $factsByKey): array
    {
        return [
            'summary_evidence' => collect($draft->summaryEvidenceFactKeys)->map(fn (string $key) => $factsByKey->get($key))->values()->all(),
            'experience' => collect($draft->experience)->map(fn (RoleSelectionDraft $role) => [
                'role_id' => $role->roleId,
                'display_title' => $role->displayTitle,
                'bullet_groups' => collect($role->bulletGroups)->map(fn (BulletGroupDraft $group, int $index) => [
                    'bullet_group_index' => $index,
                    'career_facts' => collect($group->careerFactKeys)->map(fn (string $key) => $factsByKey->get($key))->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function buildCareerFactKeysByLocation(ResumeSelectionDraft $draft): array
    {
        $byLocation = ['summary' => $draft->summaryEvidenceFactKeys];

        foreach ($draft->experience as $role) {
            foreach ($role->bulletGroups as $index => $group) {
                $byLocation["{$role->roleId}:{$index}"] = $group->careerFactKeys;
            }
        }

        foreach ($draft->targetTermUsages as $usage) {
            $key = $usage->location === ResumeTermUsageLocation::Bullet
                ? "{$usage->roleId}:{$usage->bulletGroupIndex}"
                : 'summary';

            $byLocation[$key] = array_values(array_unique([...($byLocation[$key] ?? []), ...$usage->careerFactKeys]));
        }

        return $byLocation;
    }

    /**
     * Every target term EXCEPT those with an approved `direct` posture
     * usage anywhere — those may only ever enter Stage 2's free text
     * through the deterministic qualified-clause mechanism, never as
     * free prose, even for terms Selection did approve a `qualified`/
     * `capability` claim for.
     *
     * @param  array<int, string>  $validTargetTerms
     * @return array<int, string>
     */
    private function buildDenylistTerms(array $validTargetTerms, ResumeSelectionDraft $draft): array
    {
        $directTerms = collect($draft->targetTermUsages)
            ->filter(fn (TargetTermUsageDraft $usage) => $usage->posture === ResumeClaimPosture::Direct)
            ->pluck('term')
            ->all();

        return array_values(array_diff($validTargetTerms, $directTerms));
    }

    private function appendQualifiedClause(string $baseText, ResumeQualifiedPhrase $phrase, string $term): string
    {
        $trimmed = rtrim($baseText);
        $withoutTrailingPeriod = str_ends_with($trimmed, '.') ? rtrim(substr($trimmed, 0, -1)) : $trimmed;

        return "{$withoutTrailingPeriod}, {$phrase->render()} {$term}.";
    }
}
