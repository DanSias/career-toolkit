<?php

use App\Enums\Visibility;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Models\CareerFact;
use App\Models\JobMatch;
use App\Models\Project;
use App\Models\Role;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV2;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\ResumeSelectionResponseValidator;
use App\Support\ResumeVariant\TargetTerminologyBuilder;

/**
 * Opt-in, real-provider check that ResumeSelectionPromptV2's contract
 * (system+user prompt, JSON schema, ResumeSelectionResponseValidator —
 * including the new MAX_EXPERIENCE_BULLET_GROUPS=13/MAX_SELECTED_SKILLS=18
 * content-volume budget) works against a local Ollama server, using the
 * real, already-persisted Formic JobMatch (id=1, `database/database.sqlite`
 * — the normal dev database, not live-eval.sqlite) and its real
 * CareerProfile (77 CareerFacts, 4 Roles, 16 Projects, 3 Education).
 * Lives outside phpunit.xml's default testsuites via tests/Pest.php's
 * `->in('Llm')` registration, so it never runs as part of
 * `php artisan test`. Run explicitly, by this exact file path only:
 *
 *     vendor/bin/pest tests/Llm/OllamaResumeSelectionFormicLiveTest.php
 *
 * Chosen over Pearly per the architecture investigation's own
 * conclusion: no persisted Pearly JobMatch exists anywhere (neither the
 * normal dev database nor live-eval.sqlite), and creating one now would
 * require a JobMatch provider call — out of scope for preparing this
 * harness. Formic's JobMatch already exists, real, from prior real
 * usage, requiring zero additional inference to read.
 *
 * READ-ONLY, real personal data: this harness only ever queries the
 * real dev database, and only ever reads — it never writes to it, never
 * calls GenerateResumeVariant (which would persist a ResumeVariant),
 * and never touches sources/jobs/formic-gtm-engineer.md (that source
 * markdown file is not read by any code path here; only already-
 * imported, already-persisted canonical database rows are used, the
 * same boundary every other live harness in this project respects for
 * its own source-of-record job posting). The real dev database
 * connection ('sqlite') is bound to `:memory:` for the whole Pest
 * process per phpunit.xml, so reading the real file requires a second,
 * distinct connection name — registered here at runtime only
 * (`config(['database.connections.formic_readonly' => [...]])`),
 * never added to config/database.php, and never made `database.default`
 * for longer than the one read below.
 *
 * Deliberately does NOT call GenerateResumeVariant end to end (which
 * would run Stage 2/Wording and persist a ResumeVariant + its full
 * tree) — this harness calls the transport -> ResumeSelectionResponseValidator
 * directly instead, exactly mirroring OllamaJobMatchLiveTest.php's own
 * choice, so this check never writes a ResumeVariant record anywhere,
 * in any database. The Stage-0 setup below (title choices, resume-
 * eligible roles, independent-project eligibility, project->role
 * attribution) intentionally duplicates
 * GenerateResumeVariant::generateFull()'s own private setup logic — see
 * that class for the canonical version this mirrors; it cannot be
 * called directly since Stage 1 alone, without Stage 2, isn't a method
 * that class exposes.
 *
 * A ResumeSelectionResponseValidator failure DOES fail this test (not
 * merely reported) — deliberately, same reasoning as the JobMatch
 * harnesses: the open question this experiment exists to answer is
 * specifically whether qwen3.8:27b can satisfy the new, tighter
 * content-budget contract, so a validation failure here is the primary
 * signal this harness is built to catch.
 *
 * Defaults to omitting reasoning_effort (the model's own default —
 * matching OllamaResumeSelectionClient's own production configuration
 * for this first baseline) and OllamaResumeSelectionClient's
 * production 16,000-token budget; set OLLAMA_LIVE_REASONING_EFFORT
 * and/or OLLAMA_LIVE_RESUME_SELECTION_MAX_TOKENS to opt one run into a
 * different configuration, same one-off-diagnostic env-var pattern
 * already used by the Job Match live harnesses.
 */
const OLLAMA_RESUME_SELECTION_LIVE_EVAL_JOB_MATCH_ID = 1; // Formic, real dev database

beforeEach(function () {
    if (blank(config('services.ollama.base_url'))) {
        $this->markTestSkipped('No OLLAMA_BASE_URL configured — skipping live Ollama Resume Selection check.');
    }

    $formicDatabasePath = database_path('database.sqlite');

    if (! file_exists($formicDatabasePath)) {
        $this->markTestSkipped("No dev database found at [{$formicDatabasePath}] — skipping live Ollama Resume Selection check.");
    }
});

/**
 * @return array{role_id: int, title: string}
 */
function formicTitleChoices(string $title): array
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

it('runs the real Resume Selection prompt/schema through Ollama against the real, already-persisted Formic JobMatch and reports every pipeline stage', function () {
    $originalConnection = config('database.default');

    // A second, distinct connection name pointed at the real dev
    // database file — registered here only, never persisted to
    // config/database.php. Using a name never previously resolved
    // avoids the stale-cached-PDO problem that reusing 'sqlite' itself
    // would hit (that connection is already bound to phpunit.xml's
    // :memory: for this entire process).
    config(['database.connections.formic_readonly' => [
        'driver' => 'sqlite',
        'database' => database_path('database.sqlite'),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    config(['database.default' => 'formic_readonly']);

    $jobMatch = JobMatch::with(['jobAnalysis', 'careerProfile'])->findOrFail(OLLAMA_RESUME_SELECTION_LIVE_EVAL_JOB_MATCH_ID);
    $profile = $jobMatch->careerProfile;

    $candidatePayload = (new ResumeCandidatePayloadBuilder)->build($jobMatch);
    $jobPayload = (new JobPayloadBuilder)->build($jobMatch->jobAnalysis);
    $targetTerminology = (new TargetTerminologyBuilder)->build($jobMatch);

    $validFactKeys = array_column($candidatePayload['career_facts'], 'key');
    $validSkillIds = array_column($candidatePayload['eligible_skills'], 'id');
    $validFindingIds = array_column($jobPayload['findings'], 'id');
    $validTargetTerms = array_column($targetTerminology, 'term');
    $directEvidenceExistsByTerm = array_combine($validTargetTerms, array_column($targetTerminology, 'direct_evidence_exists'));

    $resumeEligibleRoleIds = collect($candidatePayload['career_facts'])
        ->pluck('attribution.role_id')->filter(fn (mixed $id) => $id !== null)->unique()->values()->all();

    $projectIdByFactKey = collect($candidatePayload['career_facts'])
        ->filter(fn (array $fact) => $fact['attribution']['project_id'] !== null)
        ->mapWithKeys(fn (array $fact) => [$fact['key'] => $fact['attribution']['project_id']])
        ->all();

    $eligibleProjectIdsFromFacts = array_unique(array_values($projectIdByFactKey));
    $validIndependentProjectIds = Project::query()
        ->where('career_profile_id', $profile->id)
        ->whereNull('role_id')
        ->where(fn ($q) => $q->whereNull('default_visibility')->orWhere('default_visibility', '!=', Visibility::Private->value))
        ->whereIn('id', $eligibleProjectIdsFromFacts)
        ->pluck('id')->all();

    $roles = Role::query()->whereHas('employer', fn ($q) => $q->where('career_profile_id', $profile->id))->with('projects')->get();
    $validRoleIds = $roles->pluck('id')->all();
    $validProjectIds = $roles->flatMap(fn (Role $role) => $role->projects)->pluck('id')->all();

    $roleIdByProjectId = [];
    foreach ($roles as $role) {
        foreach ($role->projects as $project) {
            $roleIdByProjectId[$project->id] = $role->id;
        }
    }

    $titleChoicesByRole = $roles->mapWithKeys(fn (Role $role) => [$role->id => formicTitleChoices($role->title)])->all();
    $maxSegments = collect($titleChoicesByRole)->map(fn (array $c) => count($c) - 1)->max() ?? 0;
    $validTitleChoiceKeys = ['full', ...array_map(fn (int $i) => "segment_{$i}", range(1, max($maxSegments, 0)))];

    config(['database.default' => $originalConnection]);

    $prompt = new ResumeSelectionPromptV2;
    $schema = $prompt->jsonSchema(
        $validRoleIds, $validProjectIds, $validFactKeys, $validSkillIds,
        $validFindingIds, $validTargetTerms, $validTitleChoiceKeys, $validIndependentProjectIds,
    );

    $transport = new OllamaChatCompletionsClient;
    $validator = new ResumeSelectionResponseValidator;

    $reasoningEffort = getenv('OLLAMA_LIVE_REASONING_EFFORT') ?: null;
    $maxOutputTokens = getenv('OLLAMA_LIVE_RESUME_SELECTION_MAX_TOKENS')
        ? (int) getenv('OLLAMA_LIVE_RESUME_SELECTION_MAX_TOKENS')
        : 16000; // must track OllamaResumeSelectionClient::MAX_OUTPUT_TOKENS

    $report = [
        'job_match_id' => $jobMatch->id,
        'career_profile_id' => $profile->id,
        'provider' => 'ollama',
        'configured_model' => config('services.ollama.resume_selection_model'),
        'returned_model' => null,
        'reasoning_effort_requested' => $reasoningEffort,
        'max_tokens_requested' => $maxOutputTokens,
        'elapsed_seconds' => null,
        'finish_reason' => null,
        'prompt_tokens' => null,
        'completion_tokens' => null,
        'total_tokens' => null,
        'supplied_career_facts' => count($validFactKeys),
        'supplied_eligible_skills' => count($validSkillIds),
        'supplied_roles' => count($validRoleIds),
        'supplied_independent_projects' => count($validIndependentProjectIds),
        'supplied_target_terms' => count($validTargetTerms),
        // Comparison point: the real, already-persisted OpenAI baseline
        // for this exact JobMatch (ResumeVariant id=2, resume-selection-v1.5).
        'openai_baseline' => [
            'experience_bullet_groups' => 17,
            'selected_project_bullets' => 1,
            'skills_selected' => 22,
            'rendered_pages_observed' => 3,
        ],
        'validated_experience_bullet_groups' => null,
        'validated_selected_projects' => null,
        'validated_skills' => null,
    ];

    $start = microtime(true);

    try {
        $result = $transport->call(
            baseUrl: (string) config('services.ollama.base_url'),
            model: (string) config('services.ollama.resume_selection_model'),
            systemPrompt: $prompt->systemPrompt(),
            userPrompt: $prompt->userPrompt($candidatePayload, $jobPayload, $targetTerminology),
            schema: $schema,
            schemaName: 'resume_selection',
            maxOutputTokens: $maxOutputTokens,
            timeoutSeconds: (int) config('services.ollama.resume_selection_timeout'),
            logPrefix: 'Resume Selection generation (Ollama live check)',
            reasoningEffort: $reasoningEffort,
        );
    } catch (OllamaChatCompletionsException $e) {
        $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
        $report['returned_model'] = $e->model;
        $report['finish_reason'] = $e->finishReason;
        $report['prompt_tokens'] = $e->usage['prompt_tokens'] ?? null;
        $report['completion_tokens'] = $e->usage['completion_tokens'] ?? null;
        $report['total_tokens'] = $e->usage['total_tokens'] ?? null;
        printResumeSelectionReport($report);
        throw $e;
    }

    $report['elapsed_seconds'] = round(microtime(true) - $start, 2);
    $report['returned_model'] = $result->model;
    $report['finish_reason'] = $result->finishReason;
    $report['prompt_tokens'] = $result->usage['prompt_tokens'] ?? null;
    $report['completion_tokens'] = $result->usage['completion_tokens'] ?? null;
    $report['total_tokens'] = $result->usage['total_tokens'] ?? null;

    // A ResumeSelectionResponseValidator failure fails this test — see
    // this file's docblock for why that's deliberate here.
    try {
        $validated = $validator->validate(
            $result->structuredContent, $validRoleIds, $validFactKeys, $validSkillIds,
            $roleIdByProjectId, $validFindingIds, $titleChoicesByRole, $directEvidenceExistsByTerm,
            $resumeEligibleRoleIds, $validIndependentProjectIds, $projectIdByFactKey,
        );
    } catch (InvalidResumeVariantResponseException $e) {
        printResumeSelectionReport($report);
        fwrite(STDERR, "\n=== Ollama Resume Selection live check: VALIDATION FAILED ===\n".$e->getMessage().PHP_EOL);
        printResumeSelectionStructuredContent($e->context ?? $result->structuredContent);
        throw $e;
    }

    $totalBulletGroups = collect($validated['experience'])->sum(fn (array $role) => count($role['bullet_groups']));
    $report['validated_experience_bullet_groups'] = $totalBulletGroups;
    $report['validated_selected_projects'] = count($validated['selected_projects']);
    $report['validated_skills'] = count($validated['skills']);

    printResumeSelectionReport($report);
    printResumeSelectionStructuredContent($validated);
    printResumeSelectionQualityFlags($validated, $candidatePayload);

    // Only the transport/decode/validation steps are asserted on — the
    // quality flags above are reported, not asserted, per this file's
    // docblock; semantic quality is a human-review question.
    expect($validated['experience'])->not->toBeEmpty();
});

/**
 * @param  array<string, mixed>  $report
 */
function printResumeSelectionReport(array $report): void
{
    fwrite(STDERR, "\n".'=== Ollama Resume Selection live check: report ==='."\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

/**
 * @param  array<string, mixed>  $structuredContent
 */
function printResumeSelectionStructuredContent(array $structuredContent): void
{
    fwrite(STDERR, "\n".'=== Ollama Resume Selection live check: structured content ==='."\n".json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
}

/**
 * Surfaces (never asserts) the specific quality questions this
 * evaluation exists to answer — see this file's docblock and
 * docs/resume-variant-contract.md "Resume Selection" for the renderer
 * evidence behind the budget these flags check compliance with, and
 * PHASE 12 of the local-first Resume Selection milestone for the full
 * HARD-vs-QUALITY reporting split this harness is meant to feed.
 *
 * @param  array<string, mixed>  $validated
 * @param  array<string, mixed>  $candidatePayload
 */
function printResumeSelectionQualityFlags(array $validated, array $candidatePayload): void
{
    $flags = [];

    $totalBulletGroups = collect($validated['experience'])->sum(fn (array $role) => count($role['bullet_groups']));
    $flags[] = "NOTE: total Experience bullet groups = {$totalBulletGroups} (OpenAI baseline: 17; V2 target 10-12, hard max 13).";
    $flags[] = 'NOTE: Skills selected = '.count($validated['skills']).' (OpenAI baseline: 22; V2 target 12-16, hard max 18).';
    $flags[] = 'NOTE: Selected Projects = '.count($validated['selected_projects']).' (OpenAI baseline: 1; V2 guidance: prefer 0-2, only when it adds evidence Experience does not already tell).';

    // Skill-authorization quality flag from the architecture
    // investigation: was any selected Skill's ONLY backing CareerFact
    // lacking a `direct` JobMatch annotation? Surfaced for human
    // review — the current contract permits this deliberately (see
    // docs/domain-model.md "ResumeVariant" -> "Direct-evidence
    // authorization"), not a defect to fix in this milestone.
    $factsById = collect($candidatePayload['career_facts'])->keyBy('key');
    foreach ($validated['skills'] as $skill) {
        $skillId = $skill['skill_id'];
        $backingFacts = $factsById->filter(fn (array $fact) => collect($fact['skills'])->contains('id', $skillId));
        $hasDirectAnnotation = $backingFacts->contains(
            fn (array $fact) => collect($fact['job_match_annotations'])->contains('relationship', 'direct')
        );
        if (! $hasDirectAnnotation && $backingFacts->isNotEmpty()) {
            $flags[] = "FLAG: selected skill_id={$skillId} has no backing CareerFact with a `direct` JobMatch relationship (contextual/transferable/unannotated evidence only) — not a contract violation, worth a human read.";
        }
    }

    fwrite(STDERR, "\n".'=== Ollama Resume Selection live check: quality flags (surfaced, not asserted) ==='."\n".implode("\n", $flags).PHP_EOL);
}
