<?php

namespace App\Support\ResumeVariant;

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\JobMatch;
use App\Models\Project;
use App\Models\ResumeVariant;
use App\Models\Role;
use App\Models\Skill;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV4;
use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV2;
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
 * proven.
 *
 * As of ResumeSelectionPromptV3, Selection no longer declares which
 * Project (if any) an Experience bullet group belongs to — this class
 * derives that deterministically instead, from each bullet's own
 * approved career_fact_keys (see deriveBulletGroupProjectId()), since
 * canonical CareerFact-to-Project attribution already determines it
 * with certainty. The LLM selects evidence; canonical application data
 * determines evidence provenance. See docs/resume-variant-generation.md
 * "Design boundary: selection vs. provenance".
 */
final class GenerateResumeVariant
{
    public function __construct(
        private readonly GeneratesResumeSelection $selectionProvider,
        private readonly GeneratesResumeWording $wordingProvider,
        private readonly ResumeSelectionPromptV4 $selectionPrompt,
        private readonly ResumeWordingPromptV2 $wordingPrompt,
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
        $validSkillIds = array_column($candidatePayload['eligible_skills'], 'id');
        $validFindingIds = array_column($jobPayload['findings'], 'id');
        $validTargetTerms = array_column($targetTerminology, 'term');
        $directEvidenceExistsByTerm = array_combine($validTargetTerms, array_column($targetTerminology, 'direct_evidence_exists'));

        // Every role with at least one resume-eligible CareerFact
        // attributed to it directly or via a project — the set Selection
        // must fully represent (assertRoleCompleteness()); relevance
        // controls emphasis, never basic employment-history presence.
        $resumeEligibleRoleIds = collect($candidatePayload['career_facts'])
            ->pluck('attribution.role_id')
            ->filter(fn (mixed $roleId) => $roleId !== null)
            ->unique()
            ->values()
            ->all();

        // career_fact_key => the project_id it's actually attributed to
        // (independent or professional) — reused both to compute which
        // independent Projects are legally selectable and, in the
        // Selection validator, to confirm a selected_projects entry
        // only cites facts truly attributed to that exact Project. See
        // ResumeSelectionResponseValidator::assertSelectedProjects().
        $projectIdByFactKey = collect($candidatePayload['career_facts'])
            ->filter(fn (array $fact) => $fact['attribution']['project_id'] !== null)
            ->mapWithKeys(fn (array $fact) => [$fact['key'] => $fact['attribution']['project_id']])
            ->all();

        // Independent (role_id-null) Projects for this profile, with
        // real Public/Restricted-eligible evidence (the same visibility
        // backstop ResumeEligibility already applies at the CareerFact
        // level) — never a professional project's id. Selection may
        // choose at most MAX_SELECTED_PROJECTS of these (see
        // ResumeSelectionResponseValidator); a professional Project can
        // never be converted into a Selected Projects entry, since its
        // id is structurally absent from this set. See
        // docs/domain-model.md "ResumeVariant" -> "Selected Projects".
        $eligibleProjectIdsFromFacts = array_unique(array_values($projectIdByFactKey));
        $validIndependentProjectIds = Project::query()
            ->where('career_profile_id', $profile->id)
            ->whereNull('role_id')
            ->where(fn ($query) => $query->whereNull('default_visibility')->orWhere('default_visibility', '!=', 'private'))
            ->whereIn('id', $eligibleProjectIdsFromFacts)
            ->pluck('id')
            ->all();

        $roles = Role::query()
            ->whereHas('employer', fn ($query) => $query->where('career_profile_id', $profile->id))
            ->with('projects')
            ->get();

        $validRoleIds = $roles->pluck('id')->all();

        // role_id => the set of CareerFact keys eligible as Experience-
        // bullet evidence for that role — Role-direct facts, facts
        // attributed to one of that role's own Projects, and facts
        // attributed to the Employer that owns that role. Deliberately
        // computed from fresh, raw attribution data (attributable_type/
        // attributable_id), independent of $candidatePayload — this is
        // internal eligibility bookkeeping only, never serialized into
        // the provider-facing payload. CareerProfile-attributed facts
        // are never included here: see
        // ResumeSelectionResponseValidator::assertBulletGroupCareerFactsAreEligibleForRole()
        // and docs/resume-variant-generation.md "Design boundary:
        // selection vs. provenance".
        $eligibleFactKeysForRole = $this->eligibleFactKeysForRole($roles, $profile);

        $titleChoicesByRole = $roles->mapWithKeys(
            fn (Role $role) => [(string) $role->id => $this->titleChoices($role->title)]
        )->all();

        $validTitleChoiceKeys = $this->titleChoiceKeyEnum($titleChoicesByRole);

        // ---- Stage 1: Selection ----
        $selectionSchema = $this->selectionPrompt->jsonSchema(
            $validRoleIds,
            $validFactKeys,
            $validSkillIds,
            $validFindingIds,
            $validTargetTerms,
            $validTitleChoiceKeys,
            $validIndependentProjectIds,
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
            $validSkillIds,
            $eligibleFactKeysForRole,
            $validFindingIds,
            $titleChoicesByRole,
            $directEvidenceExistsByTerm,
            $resumeEligibleRoleIds,
            $validIndependentProjectIds,
            $projectIdByFactKey,
        );

        $selectionDraft = $this->toSelectionDraft($validatedSelection, $titleChoicesByRole, $projectIdByFactKey);

        // ---- Build Stage 2 input from exactly what Selection approved ----
        $factsByKey = collect($candidatePayload['career_facts'])->keyBy('key');
        $guardrailByFactKey = $factsByKey->map(fn (array $fact) => $fact['metric']['guardrail'] ?? null)->all();

        // For ResumeWordingResponseValidator::assertSkillProvenance() —
        // see that method's own docblock. $skillIdsByFactKey reuses the
        // same eligible-corpus facts already loaded above (no new
        // query); $canonicalSkillsById is the recognition catalog
        // (every Skill this profile's eligible corpus could ever
        // legitimately mention), deliberately reusing
        // $candidatePayload['eligible_skills'] rather than a fresh
        // Skill::query() — it already respects the same
        // ResumeEligibility boundary that governs what either provider
        // can see at all, and Wording's own generation never has a
        // legitimate reason to name a Skill outside that set.
        $skillIdsByFactKey = $factsByKey->map(fn (array $fact) => array_column($fact['skills'], 'id'))->all();
        $canonicalSkillsById = collect($candidatePayload['eligible_skills'])->pluck('name', 'id')->all();

        // The additive fact-local evidence contract established by the
        // Skill-provenance authority-model investigation — mirroring
        // JobMatchPromptV3's own evidence boundary ("that fact's own
        // statement, attached Skills, metric/guardrail/scope_note"): a
        // canonical Skill name genuinely present in a fact's own
        // evidence-bearing text is ALSO positive evidence for that
        // fact, alongside (never instead of) its attached Skill
        // relations. `metric.unit` is deliberately excluded — a short
        // value-unit classifier (e.g. "percent_reduction"), not prose
        // evidence; only `guardrail`/`scope_note` get explicit prompt
        // treatment alongside the statement itself. Computed once per
        // fact here (reusing ResumeWordingResponseValidator's own
        // recognizedSkillIds() — no second recognition algorithm), then
        // reused by every location that cites that fact — never
        // re-scanned per generated location. See
        // docs/resume-variant-generation.md "Skill provenance".
        $textRecognizedSkillIdsByFactKey = $factsByKey->map(function (array $fact) use ($canonicalSkillsById) {
            $evidenceText = trim($fact['statement'].' '.($fact['metric']['scope_note'] ?? '').' '.($fact['metric']['guardrail'] ?? ''));

            return $this->wordingValidator->recognizedSkillIds($evidenceText, $canonicalSkillsById);
        })->all();

        // The target_term_usages -> Resume Wording handoff, designed as
        // location-scoped guidance rather than a global list or a
        // second evidence channel — see
        // buildDirectTargetTermsByLocation()'s own docblock and
        // docs/resume-variant-generation.md "Target-term location
        // integrity". Deliberately built from $selectionDraft->targetTermUsages
        // (already validated by ResumeSelectionResponseValidator,
        // including the new location/evidence-locality check) rather
        // than re-deriving anything — no new query, no re-validation.
        $directTargetTermsByLocation = $this->buildDirectTargetTermsByLocation($selectionDraft);

        // The Summary's own closed-world Skill allow-list, surfaced to
        // Wording as a generation-time affordance after a deterministic
        // investigation found qwen3.8:27b stochastically borrowing
        // canonical Skills from Experience/Selected-Project evidence
        // into the summary despite an existing prose rule against it —
        // see docs/resume-variant-generation.md "Skill provenance".
        // Reuses ResumeWordingResponseValidator::authorizedSkillIds()
        // directly (now public) rather than a second definition of
        // Skill authorization: this is exactly the same set the
        // validator itself will check the generated summary against.
        // Deliberately scoped to the Summary only — no Experience/
        // Selected-Project equivalent, since only the Summary has ever
        // been observed to leak.
        $summaryAuthorizedSkillNames = collect($this->wordingValidator->authorizedSkillIds(
            $selectionDraft->summaryEvidenceFactKeys, $skillIdsByFactKey, $textRecognizedSkillIdsByFactKey,
        ))->map(fn (int $id) => $canonicalSkillsById[$id])->all();

        $wordingInput = $this->buildWordingInput($selectionDraft, $factsByKey, $directTargetTermsByLocation, $summaryAuthorizedSkillNames);
        $careerFactKeysByLocation = $this->buildCareerFactKeysByLocation($selectionDraft);
        $denylistTerms = $this->buildDenylistTerms($validTargetTerms, $selectionDraft);

        $rolesNeedingWording = collect($selectionDraft->experience)->filter(fn (RoleSelectionDraft $role) => $role->bulletGroups !== []);
        $rolesInSelection = $rolesNeedingWording->pluck('roleId')->all();
        $expectedBulletGroupIndexesByRole = $rolesNeedingWording->mapWithKeys(
            fn (RoleSelectionDraft $role) => [$role->roleId => array_keys($role->bulletGroups)]
        )->all();
        $selectedProjectIds = collect($selectionDraft->selectedProjects)->pluck('projectId')->all();

        // ---- Stage 2: Wording ----
        $wordingSchema = $this->wordingPrompt->jsonSchema($rolesInSelection, $selectedProjectIds);

        $wordingResponse = $this->wordingProvider->generate(
            $this->wordingPrompt->systemPrompt(),
            $this->wordingPrompt->userPrompt($wordingInput, $denylistTerms),
            $wordingSchema,
        );

        $validatedWording = $this->wordingValidator->validate(
            $wordingResponse->structuredContent,
            $rolesInSelection,
            $expectedBulletGroupIndexesByRole,
            $selectedProjectIds,
            $denylistTerms,
            $careerFactKeysByLocation,
            $guardrailByFactKey,
            $skillIdsByFactKey,
            $canonicalSkillsById,
            $textRecognizedSkillIdsByFactKey,
            $directTargetTermsByLocation,
        );

        $wordingDraft = $this->toWordingDraft($validatedWording);

        // ---- Persist ----
        return DB::transaction(function () use (
            $profile, $jobMatch, $selectionDraft, $wordingDraft,
            $candidatePayload, $jobPayload, $targetTerminology,
            $selectionResponse, $wordingResponse,
            $validatedSelection, $validatedWording, $wordingInput,
            $factsByKey,
        ) {
            $factIdsByKey = CareerFact::query()
                ->where('career_profile_id', $profile->id)
                ->pluck('id', 'key')
                ->all();

            $rolesById = Role::query()
                ->whereIn('id', collect($selectionDraft->experience)->pluck('roleId'))
                ->with('employer')
                ->get()
                ->keyBy('id');

            $skillsById = Skill::query()
                ->whereIn('id', collect($selectionDraft->skills)->pluck('skillId'))
                ->get()
                ->keyBy('id');

            $selectedProjectsById = Project::query()
                ->whereIn('id', collect($selectionDraft->selectedProjects)->pluck('projectId'))
                ->get()
                ->keyBy('id');

            // Education is deterministically included in full, never a
            // Selection decision — every canonical record for this
            // profile, most-recently-completed first with canonical
            // sort_order as tiebreak. See ResumeSelectionDraft's docblock.
            $educationRecords = Education::query()
                ->where('career_profile_id', $profile->id)
                ->orderByDesc('end_year')
                ->orderBy('sort_order')
                ->get();

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

            // Chronology is always deterministic, never model-decided
            // (docs/domain-model.md "ResumeVariant" -> "Titles,
            // chronology, and attribution") — the role snapshot rows'
            // own display_order is computed here, once, the same way
            // ResumeVariantController historically re-derived it on
            // every render.
            $orderedExperience = collect($selectionDraft->experience)
                ->sortByDesc(function (RoleSelectionDraft $role) use ($rolesById) {
                    $roleModel = $rolesById->get($role->roleId);

                    return sprintf('%04d-%02d', $roleModel->start_year, $roleModel->start_month ?? 1);
                })
                ->values();

            foreach ($orderedExperience as $roleDisplayOrder => $role) {
                $roleModel = $rolesById->get($role->roleId);
                $wordingRole = collect($wordingDraft->experience)->first(fn (RoleWordingDraft $r) => $r->roleId === $role->roleId);

                $experienceRole = $variant->experienceRoles()->create([
                    'role_id' => $role->roleId,
                    'employer_name' => $roleModel->employer->name,
                    'display_title' => $role->displayTitle,
                    'start_year' => $roleModel->start_year,
                    'start_month' => $roleModel->start_month,
                    'end_year' => $roleModel->end_year,
                    'end_month' => $roleModel->end_month,
                    'display_order' => $roleDisplayOrder,
                ]);

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

                    $bullet = $experienceRole->bullets()->create([
                        'resume_variant_id' => $variant->id,
                        'project_id' => $group->projectId,
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
                $skillModel = $skillsById->get($skill->skillId);

                $variant->skillSelections()->create([
                    'skill_id' => $skill->skillId,
                    'name' => $skillModel->name,
                    'category' => $skillModel->category->value,
                    'display_order' => $skill->order,
                ]);
            }

            foreach ($educationRecords as $displayOrder => $educationModel) {
                $variant->educationSelections()->create([
                    'education_id' => $educationModel->id,
                    'institution' => $educationModel->institution,
                    'degree' => $educationModel->degree,
                    'field_of_study' => $educationModel->field_of_study,
                    'start_year' => $educationModel->start_year,
                    'end_year' => $educationModel->end_year,
                    'display_order' => $displayOrder,
                ]);
            }

            // Selection's own relevance order — never chronology;
            // independent Projects carry no dates. See
            // docs/domain-model.md "ResumeVariant" -> "Selected Projects".
            $orderedSelectedProjects = collect($selectionDraft->selectedProjects)->sortBy('order')->values();

            foreach ($orderedSelectedProjects as $projectDisplayOrder => $project) {
                $projectModel = $selectedProjectsById->get($project->projectId);
                $wordingProject = collect($wordingDraft->selectedProjects)->first(fn (ProjectWordingDraft $p) => $p->projectId === $project->projectId);

                // Technology names are never model-authored (neither
                // Selection nor Wording write them) — deterministically
                // derived here from the cited facts' own attached
                // Skills, in first-appearance order across the cited
                // facts, deduplicated by name. See
                // docs/domain-model.md "ResumeVariant" -> "Selected
                // Projects".
                $technologyNames = [];
                foreach ($project->careerFactKeys as $key) {
                    foreach ($factsByKey->get($key)['skills'] as $skill) {
                        if (! in_array($skill['name'], $technologyNames, true)) {
                            $technologyNames[] = $skill['name'];
                        }
                    }
                }

                $projectRow = $variant->projects()->create([
                    'project_id' => $project->projectId,
                    'name' => $projectModel->name,
                    'technology_names' => $technologyNames,
                    'live_url' => $projectModel->live_url,
                    'repository_url' => $projectModel->repository_url,
                    'display_order' => $projectDisplayOrder,
                ]);

                // Wording's own completeness check
                // (assertSelectedProjectsCompleteness in
                // ResumeWordingResponseValidator) already guarantees a
                // matching entry exists for every approved project by
                // the time persistence runs — trusted, not re-guarded
                // here, mirroring how Experience bullets are trusted
                // above.
                foreach ($wordingProject->bullets as $bulletDisplayOrder => $text) {
                    $projectBullet = $projectRow->bullets()->create([
                        'display_order' => $bulletDisplayOrder,
                        'text' => $text,
                    ]);

                    foreach ($project->careerFactKeys as $key) {
                        $projectBullet->citations()->create(['career_fact_id' => $factIdsByKey[$key]]);
                    }
                }
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
     * The single authoritative map of a Role's legal, model-facing
     * title representations: `'full'` (the complete canonical title,
     * verbatim) plus `'segment_N'` for each of its `"/"`-delimited,
     * trimmed segments in order — present only when the title actually
     * contains a `"/"`, so a role with none (e.g. "Developer Support
     * Engineer") legally offers only `['full' => ...]`, never a
     * `segment_1` key equal to the same string. Selection returns only
     * the key; the real string is resolved from this exact map after
     * validation — never re-derived, re-cased, or fuzzy-matched. See
     * docs/resume-variant-generation.md "Live evaluation".
     *
     * @return array<string, string>
     */
    private function titleChoices(string $title): array
    {
        $segments = array_map('trim', explode('/', $title));

        if (count($segments) === 1) {
            return ['full' => $title];
        }

        $choices = ['full' => $title];

        foreach ($segments as $index => $segment) {
            $choices['segment_'.($index + 1)] = $segment;
        }

        return $choices;
    }

    /**
     * The flat, global set of title_choice tokens the Selection schema
     * offers — derived from the actual candidate roles for this run
     * (never an arbitrary permanent cap): `full`, plus `segment_1`
     * through the highest segment count any candidate role actually
     * has. A role with fewer segments than the global maximum simply
     * doesn't have the higher-numbered keys in its own
     * $titleChoicesByRole entry — the validator (not the schema) is
     * what rejects an out-of-range choice for a specific role, exactly
     * as project_id's global enum already relies on the validator for
     * per-role validity.
     *
     * @param  array<int, array<string, string>>  $titleChoicesByRole
     * @return array<int, string>
     */
    private function titleChoiceKeyEnum(array $titleChoicesByRole): array
    {
        $maxSegments = 0;

        foreach ($titleChoicesByRole as $choices) {
            $maxSegments = max($maxSegments, count($choices) - 1);
        }

        $keys = ['full'];

        for ($i = 1; $i <= $maxSegments; $i++) {
            $keys[] = "segment_{$i}";
        }

        return $keys;
    }

    /**
     * Every CareerFact eligible as Experience-bullet evidence for each
     * Role, computed from real, raw attribution — never inferred from
     * fact text, names, themes, or model output. A fact is eligible for
     * a given Role iff it is attributed directly to that Role, directly
     * to one of that Role's own Projects, or directly to the Employer
     * that owns that Role (an Employer-level fact is therefore eligible
     * for every Role at that Employer, not just one). CareerProfile-
     * attributed facts are deliberately never included in any Role's
     * eligibility set — see docs/resume-variant-generation.md "Design
     * boundary: selection vs. provenance" for why: the current canonical
     * model cannot distinguish a universally-reusable career-wide fact
     * from one describing personal/independent work that would mislead
     * if presented as evidence for paid employment. This has no effect
     * on CareerProfile-attributed facts' eligibility for
     * `summary_evidence` or Skills — both remain governed entirely by
     * `ResumeEligibility`, unrelated to this method.
     *
     * @param  Collection<int, Role>  $roles  Already loaded with their own `projects`.
     * @return array<int, array<string, true>> role_id => set of eligible CareerFact keys.
     */
    private function eligibleFactKeysForRole(Collection $roles, CareerProfile $profile): array
    {
        $eligibleFactKeysForRole = [];
        $roleIdByProjectId = [];
        $roleIdsByEmployerId = [];

        foreach ($roles as $role) {
            $eligibleFactKeysForRole[$role->id] = [];
            $roleIdsByEmployerId[$role->employer_id][] = $role->id;

            foreach ($role->projects as $project) {
                $roleIdByProjectId[$project->id] = $role->id;
            }
        }

        $facts = CareerFact::query()
            ->where('career_profile_id', $profile->id)
            ->get(['key', 'attributable_type', 'attributable_id']);

        foreach ($facts as $fact) {
            $roleIds = match ($fact->attributable_type) {
                'role' => [$fact->attributable_id],
                'project' => isset($roleIdByProjectId[$fact->attributable_id]) ? [$roleIdByProjectId[$fact->attributable_id]] : [],
                'employer' => $roleIdsByEmployerId[$fact->attributable_id] ?? [],
                // 'career_profile' (and any future attributable type)
                // is never eligible for Experience-bullet evidence.
                default => [],
            };

            foreach ($roleIds as $roleId) {
                if (array_key_exists($roleId, $eligibleFactKeysForRole)) {
                    $eligibleFactKeysForRole[$roleId][$fact->key] = true;
                }
            }
        }

        return $eligibleFactKeysForRole;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, array<string, string>>  $titleChoicesByRole
     * @param  array<string, int>  $projectIdByFactKey
     */
    private function toSelectionDraft(array $validated, array $titleChoicesByRole, array $projectIdByFactKey): ResumeSelectionDraft
    {
        /** @var array<int, string> $summaryEvidence */
        $summaryEvidence = $validated['summary_evidence'];
        /** @var array<int, array<string, mixed>> $skills */
        $skills = $validated['skills'];
        /** @var array<int, array<string, mixed>> $experience */
        $experience = $validated['experience'];
        /** @var array<int, array<string, mixed>> $selectedProjects */
        $selectedProjects = $validated['selected_projects'];
        /** @var array<int, array<string, mixed>> $targetTermUsages */
        $targetTermUsages = $validated['target_term_usages'];

        return new ResumeSelectionDraft(
            summaryEvidenceFactKeys: $summaryEvidence,
            skills: array_map(fn (array $s) => new SkillSelectionDraft($s['skill_id'], $s['order']), $skills),
            experience: array_map(fn (array $r) => new RoleSelectionDraft(
                roleId: $r['role_id'],
                // Trusted, not re-checked: ResumeSelectionResponseValidator
                // already confirmed this exact (role_id, title_choice) pair
                // is legal before persistence ever runs — resolving here
                // reads the real canonical string, never the model's own
                // text (it never supplied any).
                displayTitle: $titleChoicesByRole[$r['role_id']][$r['title_choice']],
                bulletGroups: array_map(fn (array $g) => new BulletGroupDraft(
                    projectId: $this->deriveBulletGroupProjectId($g['career_fact_keys'], $projectIdByFactKey),
                    order: $g['order'],
                    careerFactKeys: $g['career_fact_keys'],
                    jobAnalysisFindingIds: $g['job_analysis_finding_ids'],
                ), $r['bullet_groups']),
            ), $experience),
            selectedProjects: array_map(fn (array $p) => new ProjectSelectionDraft(
                projectId: $p['project_id'],
                order: $p['order'],
                careerFactKeys: $p['career_fact_keys'],
            ), $selectedProjects),
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
     * The deterministic replacement for a model-declared bullet-group
     * `project_id` (removed from the schema as of
     * ResumeSelectionPromptV3 — see that class's own docblock and
     * docs/resume-variant-generation.md "Design boundary: selection vs.
     * provenance"): every CareerFact already carries its own real,
     * canonical Project attribution, so a bullet's project is fully
     * determined by which facts Selection grouped into it — never
     * guessed from names, themes, role membership, ordering, or
     * similarity.
     *
     * - Every cited fact shares the same non-null project -> that project.
     * - Any cited fact is role-level (no project), or the cited facts
     *   span more than one project -> no specific project (`null`, the
     *   same internal representation the old `-1` sentinel resolved to).
     *
     * `assertBulletGroupCareerFactsAreEligibleForRole()` has already
     * confirmed every key here is eligible evidence for this bullet's
     * role by the time this runs; this method only narrows further, to
     * at most one project within that role.
     *
     * @param  array<int, string>  $careerFactKeys
     * @param  array<string, int>  $projectIdByFactKey
     */
    private function deriveBulletGroupProjectId(array $careerFactKeys, array $projectIdByFactKey): ?int
    {
        $projectIds = array_unique(array_map(
            fn (string $key) => $projectIdByFactKey[$key] ?? null,
            $careerFactKeys,
        ));

        if (count($projectIds) === 1 && $projectIds[0] !== null) {
            return $projectIds[0];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function toWordingDraft(array $validated): ResumeWordingDraft
    {
        /** @var array<int, array<string, mixed>> $experience */
        $experience = $validated['experience'];
        /** @var array<int, array<string, mixed>> $selectedProjects */
        $selectedProjects = $validated['selected_projects'];

        return new ResumeWordingDraft(
            summary: $validated['summary'],
            experience: array_map(fn (array $r) => new RoleWordingDraft(
                roleId: $r['role_id'],
                bullets: array_map(fn (array $b) => new BulletWordingDraft($b['bullet_group_index'], $b['text']), $r['bullets']),
            ), $experience),
            selectedProjects: array_map(fn (array $p) => new ProjectWordingDraft(
                projectId: $p['project_id'],
                bullets: [$p['text']],
            ), $selectedProjects),
        );
    }

    /**
     * Re-hydrates Stage 1's fact-key references with their real
     * canonical text for Stage 2's prompt — Stage 2 never sees raw
     * keys it would need to look anything up from. Also attaches each
     * location's own approved direct target terms (`direct_target_terms`/
     * `summary_direct_target_terms`) — see
     * buildDirectTargetTermsByLocation()'s own docblock — and the
     * Summary's own closed-world canonical-Skill allow-list
     * (`summary_authorized_skills`) — see the call site in
     * generateFull() for how it's computed. Selected Projects never
     * receive either field: `ResumeTermUsageLocation` has no "project"
     * case, so a target-term usage can never be located at one, and
     * `summary_authorized_skills` is deliberately scoped to the
     * Summary only in this milestone — nothing to attach at a Selected
     * Project for either.
     *
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     * @param  array<string, array<int, string>>  $directTargetTermsByLocation
     * @param  array<int, string>  $summaryAuthorizedSkillNames
     * @return array<string, mixed>
     */
    private function buildWordingInput(ResumeSelectionDraft $draft, Collection $factsByKey, array $directTargetTermsByLocation, array $summaryAuthorizedSkillNames): array
    {
        return [
            'summary_evidence' => collect($draft->summaryEvidenceFactKeys)->map(fn (string $key) => $factsByKey->get($key))->values()->all(),
            'summary_authorized_skills' => $summaryAuthorizedSkillNames,
            'summary_direct_target_terms' => $directTargetTermsByLocation['summary'] ?? [],
            'experience' => collect($draft->experience)->map(fn (RoleSelectionDraft $role) => [
                'role_id' => $role->roleId,
                'display_title' => $role->displayTitle,
                'bullet_groups' => collect($role->bulletGroups)->map(fn (BulletGroupDraft $group, int $index) => [
                    'bullet_group_index' => $index,
                    'career_facts' => collect($group->careerFactKeys)->map(fn (string $key) => $factsByKey->get($key))->values()->all(),
                    'direct_target_terms' => $directTargetTermsByLocation["{$role->roleId}:{$index}"] ?? [],
                ])->values()->all(),
            ])->values()->all(),
            'selected_projects' => collect($draft->selectedProjects)->map(fn (ProjectSelectionDraft $project) => [
                'project_id' => $project->projectId,
                'career_facts' => collect($project->careerFactKeys)->map(fn (string $key) => $factsByKey->get($key))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * The target_term_usages -> Resume Wording handoff, designed as
     * fact-local, location-scoped guidance rather than a global list —
     * see the target_term_usages investigation and
     * docs/resume-variant-generation.md "Target-term location
     * integrity". Only `direct`-posture usages are represented:
     * `qualified` terms are never surfaced to Wording at all (the term
     * is appended deterministically after generation by
     * appendQualifiedClause() — Wording never writes it), and
     * `capability` terms remain fully prohibited via `denylistTerms`
     * (see buildDenylistTerms()) — neither belongs in a list meant to
     * encourage a term's appearance.
     *
     * Deliberately does NOT use each usage's own `careerFactKeys` —
     * that would open a second, independent evidence channel alongside
     * the fact-local evidence Wording already receives per location,
     * which is exactly the risk this design avoids. Only `term` and
     * `location` are used: the location says WHERE a term is
     * authorized: the evidence already supplied there (via
     * buildWordingInput()) says WHAT the model may draw on to use it.
     *
     * @return array<string, array<int, string>> "summary" or "role_id:index" => term[] approved there.
     */
    private function buildDirectTargetTermsByLocation(ResumeSelectionDraft $draft): array
    {
        $byLocation = [];

        foreach ($draft->targetTermUsages as $usage) {
            if ($usage->posture !== ResumeClaimPosture::Direct) {
                continue;
            }

            $key = $usage->location === ResumeTermUsageLocation::Bullet
                ? "{$usage->roleId}:{$usage->bulletGroupIndex}"
                : 'summary';

            $byLocation[$key][] = $usage->term;
        }

        return $byLocation;
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

        foreach ($draft->selectedProjects as $project) {
            $byLocation["project:{$project->projectId}"] = $project->careerFactKeys;
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
