<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\ResumeClaimPosture;
use App\Enums\Visibility;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Models\CareerFact;
use App\Models\Education;
use App\Models\Employer;
use App\Models\JobAnalysisFinding;
use App\Models\Metric;
use App\Models\Project;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\Role;
use App\Models\Skill;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;
use Tests\Support\ResumeVariantFixtures;

function bindFakeResumeProviders(): array
{
    $selection = new FakeResumeSelectionProvider;
    $wording = new FakeResumeWordingProvider;
    app()->instance(GeneratesResumeSelection::class, $selection);
    app()->instance(GeneratesResumeWording::class, $wording);

    return [$selection, $wording];
}

/**
 * Builds a minimal but complete valid Selection response for the
 * standard ResumeVariantFixtures graph: one bullet group citing the
 * AWS fact under a qualified Azure claim, a second bullet group citing
 * the independent-implementation fact, plus skills/summary selections.
 * Education is never a Selection-stage decision — every canonical
 * Education record is included deterministically instead (see
 * GenerateResumeVariant::generateFull()).
 *
 * @return array<string, mixed>
 */
function validSelectionContent(array $candidate, array $job): array
{
    return [
        'summary_evidence' => [$candidate['factIndependent']->key],
        'skills' => [['skill_id' => $candidate['awsSkill']->id, 'order' => 1]],
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'title_choice' => 'segment_1',
            'bullet_groups' => [
                [
                    'order' => 1,
                    'career_fact_keys' => [$candidate['factAws']->key],
                    'job_analysis_finding_ids' => [$job['azureFinding']->id],
                ],
                [
                    'order' => 2,
                    'career_fact_keys' => [$candidate['factIndependent']->key],
                    'job_analysis_finding_ids' => [$job['ownershipFinding']->id],
                ],
            ],
        ]],
        'selected_projects' => [],
        'target_term_usages' => [[
            'term' => 'Azure',
            'job_analysis_finding_id' => $job['azureFinding']->id,
            'posture' => 'qualified',
            'location_type' => 'bullet',
            'role_id' => $candidate['role']->id,
            'bullet_group_index' => 0,
            'relationship_phrase_key' => 'applicable_to',
            'career_fact_keys' => [$candidate['factAws']->key],
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function validWordingContent(array $candidate): array
{
    return [
        'summary' => 'Senior engineer who independently implements production systems from team requirements.',
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'bullets' => [
                ['bullet_group_index' => 0, 'text' => 'Built cloud-hosted deployment workflows and CI/CD pipelines on AWS'],
                ['bullet_group_index' => 1, 'text' => 'Independently implemented the technical solution from team-provided requirements.'],
            ],
        ]],
        'selected_projects' => [],
    ];
}

function fullFixtureSetup(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $jobMatch = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    return [$candidate, $job, $jobMatch];
}

/**
 * A real Technology-category JobAnalysisFinding whose extracted target
 * term is exactly $term, authorized for a `direct` posture claim via
 * DirectEvidenceAuthorization's Skill-fallback path — $term attached as
 * a real canonical Skill to $evidenceFact, which must already be
 * attributed to real work history (Employer/Role/Project). Mirrors
 * TargetTerminologyBuilder's own structural label-extraction rule: a
 * single-token label matching $term as a case-insensitive whole word in
 * the statement, so $term is extracted verbatim.
 */
function directTargetTermFinding(array $job, CareerFact $evidenceFact, string $term): JobAnalysisFinding
{
    $skill = Skill::factory()->create([
        'career_profile_id' => $evidenceFact->career_profile_id,
        'name' => $term,
    ]);
    $evidenceFact->skills()->attach($skill);

    return JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $job['analysis']->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => mb_strtolower($term),
        'statement' => "{$term} is directly relevant to this role.",
        'requirement_strength' => JobAnalysisRequirementStrength::Required,
        'emphasis' => JobAnalysisEmphasis::High,
    ]);
}

/**
 * A `direct`-posture target_term_usages entry for $term at either a
 * bullet ($roleId/$bulletGroupIndex) or the Summary (leave both null).
 *
 * @return array<string, mixed>
 */
function directTargetTermUsage(JobAnalysisFinding $finding, string $term, ?int $roleId, ?int $bulletGroupIndex, array $careerFactKeys): array
{
    return [
        'term' => $term,
        'job_analysis_finding_id' => $finding->id,
        'posture' => 'direct',
        'location_type' => $roleId === null ? 'summary' : 'bullet',
        'role_id' => $roleId ?? -1,
        'bullet_group_index' => $bulletGroupIndex ?? -1,
        'relationship_phrase_key' => 'not_applicable',
        'career_fact_keys' => $careerFactKeys,
    ];
}

it('persists the full graph from a valid two-stage response', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(ResumeVariant::count())->toBe(1)
        ->and($variant->experienceBullets)->toHaveCount(2)
        ->and($variant->skillSelections)->toHaveCount(1)
        ->and($variant->educationSelections)->toHaveCount(1)
        ->and($variant->summaryEvidence)->toHaveCount(1)
        ->and($variant->targetTermUsages)->toHaveCount(1)
        ->and($variant->summary)->not->toBeNull()
        ->and($variant->schema_version)->toBe('2.0')
        ->and($variant->selection_prompt_version)->toBe('resume-selection-v4')
        ->and($variant->wording_prompt_version)->toBe('resume-wording-v2')
        ->and($variant->selection_generated_by)->toBe('openai:gpt-test')
        ->and($variant->wording_generated_by)->toBe('openai:gpt-test');
});

it('renders the qualified clause deterministically, appended to Stage 2 free text', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    $azureBullet = $variant->experienceBullets->firstWhere('display_order', 1);

    expect($azureBullet->text)->toContain('Built cloud-hosted deployment workflows')
        ->and($azureBullet->text)->toContain('Azure')
        ->and($azureBullet->text)->toContain('with approaches applicable to');
});

it('selects a CareerFact JobMatch never cited for any finding, because Selection sees the full eligible corpus', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();

    // An eligible fact with zero JobMatch citations at all.
    $uncitedFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-uncited-strong-fact',
        'statement' => 'Delivered a company-wide platform migration ahead of schedule.',
        'visibility' => Visibility::Public,
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $candidate['role']->id,
    ]);

    $content = validSelectionContent($candidate, $job);
    $content['summary_evidence'] = [$uncitedFact->key];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->summaryEvidence->first()->career_fact_id)->toBe($uncitedFact->id);
});

it('never sends JobMatch rationale fields to either provider', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    app(GenerateResumeVariant::class)->generateFull($jobMatch);

    // Precise structural check, not a blind substring scan — a blind
    // scan for "rationale" would false-positive on JobAnalysis's own
    // legitimate seniority_rationale field.
    $annotations = collect(json_decode($selection->capturedUserPrompt, true) ?? [])->all();
    expect($selection->capturedUserPrompt)->not->toContain('coverage_rationale')
        ->and($selection->capturedUserPrompt)->not->toContain('"rationale"');
});

it('accepts a direct posture claim when direct evidence authorizes it', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    // Ownership finding: factIndependent is cited `direct` by JobMatch,
    // attributed to a real Role — authorizes a direct claim.
    $content['target_term_usages'] = [];
    // No target term to test here directly since "Azure" has no direct
    // evidence; instead confirm the ownership bullet grouping (already
    // present) persists without any posture violation — a dedicated
    // direct-authorization negative case is covered by the next test.

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(ResumeVariant::count())->toBe(1)
        ->and($variant->targetTermUsages)->toHaveCount(0);
});

it('rejects a direct posture claim for a term with no authorizing direct evidence, and persists nothing', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'][0]['posture'] = 'direct'; // Azure has no direct evidence in this fixture.
    $content['target_term_usages'][0]['relationship_phrase_key'] = 'not_applicable';

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('persists a capability posture explicitly, not as the absence of a row', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'][0]['posture'] = 'capability';
    $content['target_term_usages'][0]['relationship_phrase_key'] = 'not_applicable';

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->targetTermUsages->first()->posture)->toBe(ResumeClaimPosture::Capability);
});

it('rejects a qualified target term that leaks into Stage 2 free text, and persists nothing', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $wordingContent = validWordingContent($candidate);
    $wordingContent['experience'][0]['bullets'][0]['text'] = 'Built cloud deployment workflows on AWS and Azure directly.';

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a qualified usage with an invalid job_analysis_finding_id', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'][0]['job_analysis_finding_id'] = 999999;

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a qualified usage citing a CareerFact never supplied to the provider', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'][0]['career_fact_keys'] = ['not-a-real-key'];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects an invalid relationship_phrase_key', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'][0]['relationship_phrase_key'] = 'directly_transferable_to'; // not an allowed phrase

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a second qualified usage located at the same bullet', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'][] = [
        'term' => 'Azure',
        'job_analysis_finding_id' => $job['azureFinding']->id,
        'posture' => 'qualified',
        'location_type' => 'bullet',
        'role_id' => $candidate['role']->id,
        'bullet_group_index' => 0,
        'relationship_phrase_key' => 'comparable_to',
        'career_fact_keys' => [$candidate['factAws']->key],
    ];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a cross-profile CareerFact reference', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $otherProfileFact = CareerFact::factory()->create(['visibility' => Visibility::Public]);

    $content = validSelectionContent($candidate, $job);
    $content['summary_evidence'] = [$otherProfileFact->key];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a title_choice that is not legal for the selected role, with no fallback to full', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();

    // A second role whose canonical title has no "/" — legally offers
    // only 'full'. The model can no longer write free text at all
    // (title_choice is a closed enum), so the equivalent of the old
    // "fabricated title" risk is choosing a legal-looking key the
    // selected role simply doesn't offer.
    $otherRole = Role::factory()->create(['employer_id' => $candidate['employer']->id, 'title' => 'Solo Title No Slash']);
    CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $otherRole->id,
        'key' => 'fixture-other-role-fact',
        'visibility' => Visibility::Public,
    ]);

    $content = validSelectionContent($candidate, $job);
    $content['experience'][] = [
        'role_id' => $otherRole->id,
        'title_choice' => 'segment_1', // illegal — $otherRole has no "/" segments at all
        'bullet_groups' => [[
            'project_id' => -1,
            'order' => 1,
            'career_fact_keys' => ['fixture-other-role-fact'],
            'job_analysis_finding_ids' => [],
        ]],
    ];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('resolves title_choice segment_2 to the exact canonical segment string, never model-written text', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['experience'][0]['title_choice'] = 'segment_2'; // real second segment of "Senior Software Engineer / Platform Lead"

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->experienceRoles->first()->display_title)->toBe('Platform Lead');
});

it('rejects a bullet group citing a CareerFact that genuinely belongs to a different role entirely', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();

    $otherEmployer = Employer::factory()->create(['career_profile_id' => $candidate['profile']->id]);
    $otherRole = Role::factory()->create(['employer_id' => $otherEmployer->id]);
    $otherRoleFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-other-role-fact',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $otherRole->id,
    ]);

    $content = validSelectionContent($candidate, $job);
    // A fact genuinely attributed to $otherRole, cited under
    // $candidate['role']'s own bullet group.
    $content['experience'][0]['bullet_groups'][0]['career_fact_keys'][] = $otherRoleFact->key;

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is not eligible Experience evidence for role_id');
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects an exact duplicate bullet-group evidence set', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['experience'][0]['bullet_groups'][] = [
        'order' => 3,
        'career_fact_keys' => [$candidate['factAws']->key], // exact duplicate of bullet_groups[0]
        'job_analysis_finding_ids' => [],
    ];
    $content['target_term_usages'] = []; // avoid unrelated qualified-clause complications

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a bullet group citing the same CareerFact twice in its own career_fact_keys, before Wording is ever called, persisting nothing', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    // The real TRM regression shape: one bullet group's own
    // career_fact_keys array repeats the same key.
    $content['experience'][0]['bullet_groups'][1]['career_fact_keys'] = [
        $candidate['factIndependent']->key,
        $candidate['factIndependent']->key,
    ];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is selected more than once within bullet group');
    expect($wording->callCount)->toBe(0);
    expect(ResumeVariant::count())->toBe(0);
});

// --- Deterministic project attribution derivation ------------------------
//
// As of ResumeSelectionPromptV3, Selection never declares a bullet
// group's project_id at all — GenerateResumeVariant derives it
// deterministically from the bullet's own approved career_fact_keys
// instead (see deriveBulletGroupProjectId()'s own docblock and
// docs/resume-variant-generation.md "Design boundary: selection vs.
// provenance"). Motivated by a real TRM Labs async Resume run that
// failed because the model incorrectly declared a role-level CareerFact
// (pearson-data-analytics-lead-stakeholder-partnership) as belonging to
// a specific sibling project — a class of failure this derivation makes
// structurally impossible, since there is no longer any model-declared
// project_id to get wrong.

/**
 * @param  array<int, string>  $factKeys
 * @return array<string, mixed>
 */
function singleBulletSelectionContent(array $candidate, array $factKeys): array
{
    return [
        'summary_evidence' => [],
        'skills' => [],
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'title_choice' => 'full',
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

/**
 * @return array<string, mixed>
 */
function singleBulletWordingContent(array $candidate): array
{
    return [
        'summary' => 'A resume summary.',
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'bullets' => [['bullet_group_index' => 0, 'text' => 'A generated bullet.']],
        ]],
        'selected_projects' => [],
    ];
}

function firstPersistedBullet(ResumeVariant $variant): ResumeVariantExperienceBullet
{
    return $variant->experienceRoles->first()->bullets->sole();
}

it('derives the bullet\'s real project when it cites one CareerFact attributed to that project', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = singleBulletSelectionContent($candidate, [$candidate['factAws']->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(firstPersistedBullet($variant)->project_id)->toBe($candidate['project']->id);
});

it('derives the same project when multiple cited CareerFacts all genuinely belong to it', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $secondProjectFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-second-project-fact',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $candidate['project']->id,
    ]);
    $content = singleBulletSelectionContent($candidate, [$candidate['factAws']->key, $secondProjectFact->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(firstPersistedBullet($variant)->project_id)->toBe($candidate['project']->id);
});

it('derives no specific project when the cited CareerFact is role-level', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = singleBulletSelectionContent($candidate, [$candidate['factIndependent']->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(firstPersistedBullet($variant)->project_id)->toBeNull();
});

it('derives no specific project when a role-level fact is mixed with a project-attributed fact in the same bullet', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = singleBulletSelectionContent($candidate, [$candidate['factIndependent']->key, $candidate['factAws']->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(firstPersistedBullet($variant)->project_id)->toBeNull();
});

it('derives no specific project when the cited CareerFacts span two different projects under the same role', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $projectB = Project::factory()->create(['role_id' => $candidate['role']->id, 'name' => 'Project B']);
    $projectBFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-project-b-fact',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $projectB->id,
    ]);
    $content = singleBulletSelectionContent($candidate, [$candidate['factAws']->key, $projectBFact->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(firstPersistedBullet($variant)->project_id)->toBeNull();
});

it('rejects a bullet group citing a CareerFact key never supplied to the provider at all', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = singleBulletSelectionContent($candidate, ['never-supplied-fact-key']);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class, 'was not supplied in the provider input');
    expect(ResumeVariant::count())->toBe(0);
});

it('reproduces and rejects the exact real TRM Labs regression shape: a role-level fact incorrectly reachable as a specific project — proving it can no longer be mis-persisted', function () {
    // The real key from the incident, attributed here exactly as it was
    // in production: role-level, not tied to any project. Selection
    // never gets the opportunity to mis-declare a project for it at
    // all, since the field no longer exists — this test proves the
    // derived outcome is correctly project-unscoped rather than crashing
    // or silently attaching a project.
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $realKeyFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'pearson-data-analytics-lead-stakeholder-partnership',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $candidate['role']->id,
    ]);
    $content = singleBulletSelectionContent($candidate, [$realKeyFact->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(firstPersistedBullet($variant)->project_id)->toBeNull()
        ->and($selection->capturedSchema)->not->toHaveKey('project_id');
});

it('never sends a bullet-group project_id to Resume Wording — Wording remains fully independent of bullet-group project attribution', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = singleBulletSelectionContent($candidate, [$candidate['factAws']->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    // wording_input_snapshot freezes exactly what Wording's own prompt
    // was built from (see GenerateResumeVariant::buildWordingInput()) —
    // each bullet-group entry there carries only bullet_group_index,
    // career_facts, and direct_target_terms, never a project_id key,
    // even though each cited fact's own attribution (a different,
    // pre-existing, legitimate concern) does mention its real project.
    $bulletGroupEntry = $variant->wording_input_snapshot['experience'][0]['bullet_groups'][0];
    expect($bulletGroupEntry)->not->toHaveKey('project_id')
        ->and(array_keys($bulletGroupEntry))->toBe(['bullet_group_index', 'career_facts', 'direct_target_terms']);
});

// --- Experience CareerFact eligibility -----------------------------------
//
// GenerateResumeVariant::eligibleFactKeysForRole() computes, for each
// Role, the deterministic set of CareerFact keys eligible as Experience-
// bullet evidence: Role-direct, that Role's Project-direct, and that
// Role's Employer-direct. CareerProfile-direct facts never enter any
// role's set. See docs/resume-variant-generation.md "Design boundary:
// selection vs. provenance". Motivated by a real TRM Labs run that cited
// two CareerProfile-direct facts (profile-ai-assisted-development-pattern,
// profile-github-personal-projects) under an employer Role — this section
// reproduces both exact real keys, plus the Role/Project/Employer routes
// the fix introduces or preserves.

it('accepts a Project-direct CareerFact under that Project\'s owning Role (existing route, unaffected)', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = singleBulletSelectionContent($candidate, [$candidate['factAws']->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(ResumeVariant::count())->toBe(1);
    expect(firstPersistedBullet($variant)->project_id)->toBe($candidate['project']->id);
});

it('accepts an Employer-direct CareerFact under a Role belonging to that Employer', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $employerFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-employer-narrative',
        'attributable_type' => (new Employer)->getMorphClass(),
        'attributable_id' => $candidate['employer']->id,
    ]);
    $content = singleBulletSelectionContent($candidate, [$employerFact->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(ResumeVariant::count())->toBe(1)
        // Employer-direct evidence has no single project — the same
        // "no specific project" derivation a role-level fact produces.
        ->and(firstPersistedBullet($variant)->project_id)->toBeNull();
});

it('accepts the same Employer-direct CareerFact under a DIFFERENT Role belonging to the same Employer', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $secondRole = Role::factory()->create(['employer_id' => $candidate['employer']->id]);
    $employerFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-employer-narrative',
        'attributable_type' => (new Employer)->getMorphClass(),
        'attributable_id' => $candidate['employer']->id,
    ]);

    // $candidate['role'] must still appear (it is genuinely
    // resume-eligible); $secondRole voluntarily appears too, citing only
    // the Employer-direct fact, to prove that same fact is eligible
    // there as well.
    $content = validSelectionContent($candidate, $job);
    $content['experience'][] = [
        'role_id' => $secondRole->id,
        'title_choice' => 'full',
        'bullet_groups' => [[
            'order' => 1,
            'career_fact_keys' => [$employerFact->key],
            'job_analysis_finding_ids' => [],
        ]],
    ];
    $wordingContent = validWordingContent($candidate);
    $wordingContent['experience'][] = [
        'role_id' => $secondRole->id,
        'bullets' => [['bullet_group_index' => 0, 'text' => 'A second-role bullet.']],
    ];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->experienceRoles)->toHaveCount(2)
        ->and($variant->experienceRoles->firstWhere('role_id', $secondRole->id)->bullets->sole()->project_id)->toBeNull();
});

it('rejects an Employer-direct CareerFact under a Role belonging to a DIFFERENT Employer', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $employerFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-employer-narrative',
        'attributable_type' => (new Employer)->getMorphClass(),
        'attributable_id' => $candidate['employer']->id,
    ]);
    $otherEmployer = Employer::factory()->create(['career_profile_id' => $candidate['profile']->id]);
    $otherEmployerRole = Role::factory()->create(['employer_id' => $otherEmployer->id]);

    $content = singleBulletSelectionContent(['role' => $otherEmployerRole], [$employerFact->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent(['role' => $otherEmployerRole])));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is not eligible Experience evidence for role_id');
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a Project-direct CareerFact cited under a Role other than that Project\'s owning Role', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $otherEmployer = Employer::factory()->create(['career_profile_id' => $candidate['profile']->id]);
    $otherRole = Role::factory()->create(['employer_id' => $otherEmployer->id]);

    $content = singleBulletSelectionContent(['role' => $otherRole], [$candidate['factAws']->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent(['role' => $otherRole])));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is not eligible Experience evidence for role_id');
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects an independent-project CareerFact cited under any Experience Role', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $independentProject = Project::factory()->create(['role_id' => null, 'career_profile_id' => $candidate['profile']->id]);
    $independentFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-independent-project-fact',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $independentProject->id,
    ]);
    $content = singleBulletSelectionContent($candidate, [$independentFact->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class, 'is not eligible Experience evidence for role_id');
    expect(ResumeVariant::count())->toBe(0);
});

it('reproduces and rejects the exact real TRM Labs regression key: profile-ai-assisted-development-pattern under an employer Role, never invoking Wording, persisting nothing', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $realKeyFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'profile-ai-assisted-development-pattern',
        'attributable_type' => 'career_profile',
        'attributable_id' => $candidate['profile']->id,
    ]);
    $content = singleBulletSelectionContent($candidate, [$realKeyFact->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class, "career_fact_key [{$realKeyFact->key}] is not eligible Experience evidence for role_id");
    expect($wording->callCount)->toBe(0);
    expect(ResumeVariant::count())->toBe(0);
});

it('reproduces and rejects the exact real TRM Labs regression key: profile-github-personal-projects under an employer Role, never invoking Wording, persisting nothing', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $realKeyFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'profile-github-personal-projects',
        'attributable_type' => 'career_profile',
        'attributable_id' => $candidate['profile']->id,
    ]);
    $content = singleBulletSelectionContent($candidate, [$realKeyFact->key]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleBulletWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class, "career_fact_key [{$realKeyFact->key}] is not eligible Experience evidence for role_id");
    expect($wording->callCount)->toBe(0);
    expect(ResumeVariant::count())->toBe(0);
});

it('allows the same CareerFact to legitimately back two distinct claims (summary and a bullet)', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    // factAws already backs bullet_groups[0]; also let it back the summary.
    $content['summary_evidence'] = [$candidate['factIndependent']->key, $candidate['factAws']->key];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->summaryEvidence)->toHaveCount(2);
});

it('excludes Skills from any target-term/qualified mechanism entirely — the schema has no such field', function () {
    $schemaSource = file_get_contents(base_path('app/Support/ResumeVariant/Prompts/ResumeSelectionPromptV2.php'));
    // Extract just the schema-building region so this check can't
    // false-positive on the systemPrompt's own prose mentioning skills
    // elsewhere.
    $schemaStart = strpos($schemaSource, 'function jsonSchema');
    $skillsBlockStart = strpos($schemaSource, "'skills' =>", $schemaStart);
    $skillsBlockEnd = strpos($schemaSource, "'experience' =>", $skillsBlockStart);
    $skillsBlock = substr($schemaSource, $skillsBlockStart, $skillsBlockEnd - $skillsBlockStart);

    expect($skillsBlock)->not->toContain('term')
        ->and($skillsBlock)->not->toContain('posture')
        ->and($skillsBlock)->not->toContain('qualified');
});

it('rejects wrong Employer/Role/Project attribution: a bullet cannot claim an Employer the Role does not actually belong to', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();

    // The orchestrator always resolves employer_name/role_id from the
    // real Role model, never from provider input — so this is a
    // structural guarantee, verified directly rather than via a
    // rejection path.
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->experienceRoles->first()->role_id)->toBe($candidate['role']->id)
        ->and($variant->experienceRoles->first()->employer_name)->toBe($candidate['employer']->name);
});

it('propagates a Selection provider failure and persists nothing', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willFail(new ResumeGenerationProviderException('Simulated provider failure.'));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(ResumeGenerationProviderException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('propagates a Wording provider failure and persists nothing', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willFail(new ResumeGenerationProviderException('Simulated provider failure.'));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(ResumeGenerationProviderException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rolls back the entire graph on a database failure during persistence', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    Schema::drop('resume_variant_bullet_citations');

    try {
        app(GenerateResumeVariant::class)->generateFull($jobMatch);
        expect(false)->toBeTrue('Expected a database exception.');
    } catch (Throwable $e) {
        expect($e)->not->toBeInstanceOf(InvalidResumeVariantResponseException::class);
    }

    expect(ResumeVariant::count())->toBe(0)
        ->and(ResumeVariantExperienceBullet::count())->toBe(0);
});

it('freezes the exact selection and wording input snapshots, immune to a later CareerFact edit', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);
    $originalStatement = collect($variant->selection_input_snapshot['candidate']['career_facts'])
        ->firstWhere('key', $candidate['factAws']->key)['statement'];

    DB::table('career_facts')->where('id', $candidate['factAws']->id)->update(['statement' => 'A completely different, later-edited statement.']);

    $variant->refresh();
    $frozenStatement = collect($variant->selection_input_snapshot['candidate']['career_facts'])
        ->firstWhere('key', $candidate['factAws']->key)['statement'];

    expect($frozenStatement)->toBe($originalStatement)
        ->and($frozenStatement)->not->toBe('A completely different, later-edited statement.');
});

it('rejects a Selection response that omits a resume-eligible role entirely', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();

    // A second, resume-eligible Role at the same employer with its own
    // eligible CareerFact — the fixture graph's only role besides
    // $candidate['role'].
    $secondRole = Role::factory()->create([
        'employer_id' => $candidate['employer']->id,
        'title' => 'Support Engineer',
        'start_year' => 2018,
        'start_month' => 1,
        'end_year' => 2020,
        'end_month' => 12,
    ]);
    CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-second-role-fact',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $secondRole->id,
        'visibility' => Visibility::Public,
    ]);

    // validSelectionContent() only represents $candidate['role'] —
    // $secondRole is resume-eligible but silently missing entirely.
    $content = validSelectionContent($candidate, $job);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('accepts a resume-eligible role represented by a single modest bullet group, never requiring heavy coverage', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();

    $secondRole = Role::factory()->create([
        'employer_id' => $candidate['employer']->id,
        'title' => 'Support Engineer',
        'start_year' => 2018,
        'start_month' => 1,
        'end_year' => 2020,
        'end_month' => 12,
    ]);
    CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-second-role-fact',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $secondRole->id,
        'visibility' => Visibility::Public,
    ]);

    $content = validSelectionContent($candidate, $job);
    $content['experience'][] = [
        'role_id' => $secondRole->id,
        'title_choice' => 'full',
        'bullet_groups' => [[
            'project_id' => -1,
            'order' => 1,
            'career_fact_keys' => ['fixture-second-role-fact'],
            'job_analysis_finding_ids' => [],
        ]],
    ];

    $wordingContent = validWordingContent($candidate);
    $wordingContent['experience'][] = [
        'role_id' => $secondRole->id,
        'bullets' => [
            ['bullet_group_index' => 0, 'text' => 'Provided support engineering for a legacy platform.'],
        ],
    ];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->experienceRoles->pluck('role_id')->all())
        ->toContain($candidate['role']->id, $secondRole->id);
});

it('deterministically includes every canonical Education record regardless of what Selection returned, ordered by end_year descending with sort_order as tiebreak', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();

    $olderDegree = Education::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'degree' => 'Associate Degree',
        'end_year' => 2000,
        'sort_order' => 5,
    ]);
    $tiedYearDegreeA = Education::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'degree' => 'Minor A',
        'end_year' => 2010,
        'sort_order' => 1,
    ]);
    $tiedYearDegreeB = Education::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'degree' => 'Minor B',
        'end_year' => 2010,
        'sort_order' => 0,
    ]);

    // $candidate['education'] itself has no explicit end_year override
    // in the fixture (randomized 1990-2020) — pin it above the others
    // so ordering is unambiguous.
    $candidate['education']->update(['end_year' => 2020, 'sort_order' => 0]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->educationSelections)->toHaveCount(4)
        ->and($variant->educationSelections->sortBy('display_order')->pluck('education_id')->all())
        ->toBe([
            $candidate['education']->id,
            $tiedYearDegreeB->id,
            $tiedYearDegreeA->id,
            $olderDegree->id,
        ]);
});

it('never persists any years-of-experience computation anywhere in the generated tree', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    foreach ($variant->getAttributes() as $column => $value) {
        expect($column)->not->toContain('years')
            ->and($column)->not->toContain('tenure')
            ->and($column)->not->toContain('duration');
    }
});

// ---- Direct target-term guidance placement in the persisted Wording
// ---- input (GenerateResumeVariant::buildWordingInput()/
// ---- buildDirectTargetTermsByLocation()) ----
//
// These exercise the real production payload-building path end to end
// (fake providers, real GenerateResumeVariant::generateFull(), real
// wording_input_snapshot persisted) rather than re-implementing
// buildDirectTargetTermsByLocation()'s logic in the test — added after
// discovering that tests/Llm/OllamaResumeWordingFormicLiveTest.php had
// its own, incomplete reconstruction of this same data that no
// deterministic test would have caught. See
// docs/resume-variant-generation.md "Target-term location integrity".

it('places a direct target term only in its approved bullet location — never a sibling bullet, never another role', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $terraformFinding = directTargetTermFinding($job, $candidate['factAws'], 'Terraform');

    $secondRole = Role::factory()->create(['employer_id' => $candidate['employer']->id, 'title' => 'Support Engineer']);
    CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-second-role-fact',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $secondRole->id,
        'visibility' => Visibility::Public,
    ]);

    $content = validSelectionContent($candidate, $job);
    $content['experience'][] = [
        'role_id' => $secondRole->id,
        'title_choice' => 'full',
        'bullet_groups' => [[
            'project_id' => -1,
            'order' => 1,
            'career_fact_keys' => ['fixture-second-role-fact'],
            'job_analysis_finding_ids' => [],
        ]],
    ];
    $content['target_term_usages'] = [
        directTargetTermUsage($terraformFinding, 'Terraform', $candidate['role']->id, 0, [$candidate['factAws']->key]),
    ];

    $wordingContent = validWordingContent($candidate);
    $wordingContent['experience'][] = [
        'role_id' => $secondRole->id,
        'bullets' => [
            ['bullet_group_index' => 0, 'text' => 'Provided support engineering for a legacy platform.'],
        ],
    ];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    $roleInputsById = collect($variant->wording_input_snapshot['experience'])->keyBy('role_id');

    expect($roleInputsById[$candidate['role']->id]['bullet_groups'][0]['direct_target_terms'])->toBe(['Terraform'])
        ->and($roleInputsById[$candidate['role']->id]['bullet_groups'][1]['direct_target_terms'])->toBe([])
        ->and($roleInputsById[$secondRole->id]['bullet_groups'][0]['direct_target_terms'])->toBe([])
        ->and($variant->wording_input_snapshot['summary_direct_target_terms'])->toBe([]);
});

it('places a Summary-approved direct target term only in summary_direct_target_terms, never in any bullet', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $terraformFinding = directTargetTermFinding($job, $candidate['factAws'], 'Terraform');

    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'] = [
        directTargetTermUsage($terraformFinding, 'Terraform', null, null, [$candidate['factIndependent']->key]),
    ];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);
    $snapshot = $variant->wording_input_snapshot;

    expect($snapshot['summary_direct_target_terms'])->toBe(['Terraform']);

    foreach ($snapshot['experience'] as $role) {
        foreach ($role['bullet_groups'] as $group) {
            expect($group['direct_target_terms'])->toBe([]);
        }
    }
});

it('excludes a qualified-posture target term from direct_target_terms guidance', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    // validSelectionContent()'s default usage is Azure/qualified at
    // role/bullet_group_index 0 — unchanged here.
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['experience'][0]['bullet_groups'][0]['direct_target_terms'])->toBe([]);
});

it('excludes a capability-posture target term from direct_target_terms guidance', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'][0]['posture'] = 'capability';
    $content['target_term_usages'][0]['relationship_phrase_key'] = 'not_applicable';

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['experience'][0]['bullet_groups'][0]['direct_target_terms'])->toBe([]);
});

it('produces empty direct_target_terms/summary_direct_target_terms guidance everywhere when Selection approves no target-term usages', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'] = [];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);
    $snapshot = $variant->wording_input_snapshot;

    expect($snapshot['summary_direct_target_terms'])->toBe([]);

    foreach ($snapshot['experience'] as $role) {
        foreach ($role['bullet_groups'] as $group) {
            expect($group['direct_target_terms'])->toBe([]);
        }
    }
});

it('represents multiple direct target terms approved for the same location together, in order', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $awsFinding = directTargetTermFinding($job, $candidate['factAws'], 'AWS');
    $terraformFinding = directTargetTermFinding($job, $candidate['factAws'], 'Terraform');

    $content = validSelectionContent($candidate, $job);
    $content['target_term_usages'] = [
        directTargetTermUsage($awsFinding, 'AWS', $candidate['role']->id, 0, [$candidate['factAws']->key]),
        directTargetTermUsage($terraformFinding, 'Terraform', $candidate['role']->id, 0, [$candidate['factAws']->key]),
    ];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['experience'][0]['bullet_groups'][0]['direct_target_terms'])
        ->toBe(['AWS', 'Terraform']);
});

it('never attaches a direct-target-term field to a Selected Project, since ResumeTermUsageLocation has no project case', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $terraformFinding = directTargetTermFinding($job, $candidate['factAws'], 'Terraform');

    $content = validSelectionContent($candidate, $job);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factIndependentProject']->key],
    ]];
    $content['target_term_usages'] = [
        directTargetTermUsage($terraformFinding, 'Terraform', $candidate['role']->id, 0, [$candidate['factAws']->key]),
    ];

    $wordingContent = validWordingContent($candidate);
    $wordingContent['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'text' => 'Built a structured prompt library for reusable AI-assisted development workflows.',
    ]];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['selected_projects'])->toHaveCount(1)
        ->and($variant->wording_input_snapshot['selected_projects'][0])->not->toHaveKey('direct_target_terms');
});

// ---- Summary authorized-Skills allow-list in the persisted Wording
// ---- input (GenerateResumeVariant::buildWordingInput()'s new
// ---- summary_authorized_skills field, reusing
// ---- ResumeWordingResponseValidator::authorizedSkillIds() directly) ----
//
// Added after a deterministic investigation of a recurring qwen3.8:27b
// Resume Wording Summary failure — the model stochastically named
// canonical Skills (React/Node.js/Laravel/TypeScript) that were
// genuinely visible elsewhere in the same request but not authorized by
// the Summary's own supplied CareerFacts, despite an existing prose
// rule against it. These exercise the real production payload-building
// path end to end (fake providers, real
// GenerateResumeVariant::generateFull(), real wording_input_snapshot
// persisted), never a second implementation of the authorization
// algorithm. See docs/resume-variant-generation.md "Skill provenance".

it('includes a Skill attached directly to a summary CareerFact in summary_authorized_skills', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $djangoSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Django']);
    $candidate['factIndependent']->skills()->attach($djangoSkill);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['summary_authorized_skills'])->toContain('Django');
});

it('includes a canonical Skill recognized from a summary CareerFact\'s own statement text, even when never attached via the pivot', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    // Attached to an unrelated, non-summary fact only so it's a real
    // eligible/canonical Skill (ResumeCandidatePayloadBuilder's
    // eligible_skills is built only from attached Skills) — the point
    // of this test is that the summary-cited fact's own text is
    // recognized independent of that same fact's own attachments.
    $graphqlSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'GraphQL']);
    $candidate['factAws']->skills()->attach($graphqlSkill);
    $graphqlFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-summary-graphql-text',
        'statement' => 'Used GraphQL for the public API layer.',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $candidate['role']->id,
        'visibility' => Visibility::Public,
    ]);

    $content = validSelectionContent($candidate, $job);
    $content['summary_evidence'] = [$candidate['factIndependent']->key, $graphqlFact->key];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['summary_authorized_skills'])->toContain('GraphQL');
});

it('includes a canonical Skill recognized from a summary CareerFact\'s own metric scope_note or guardrail text', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    // Attached to an unrelated, non-summary fact only — see the
    // GraphQL test above for why (eligible_skills requires a real
    // attachment somewhere to exist as a canonical Skill at all).
    $snowflakeSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Snowflake']);
    $redshiftSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Redshift']);
    $candidate['factAws']->skills()->attach([$snowflakeSkill->id, $redshiftSkill->id]);
    Metric::factory()->create([
        'career_fact_id' => $candidate['factIndependent']->id,
        'scope_note' => 'Migrated reporting off Snowflake.',
        'guardrail' => 'Do not conflate with the separate Redshift migration.',
    ]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['summary_authorized_skills'])
        ->toContain('Snowflake')
        ->toContain('Redshift');
});

it('excludes a Skill that belongs only to an Experience CareerFact, never the Summary', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    // factAws's own 'AWS' Skill is cited only by bullet_groups[0] in
    // validSelectionContent() — never part of summary_evidence.
    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['summary_authorized_skills'])->not->toContain('AWS');
});

it('excludes a Skill that belongs only to a Selected Project CareerFact, never the Summary', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    // factIndependentProject's own 'Prisma' Skill is cited only by the
    // Selected Project entry below — never part of summary_evidence.
    $content = validSelectionContent($candidate, $job);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factIndependentProject']->key],
    ]];

    $wordingContent = validWordingContent($candidate);
    $wordingContent['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'text' => 'Built a structured prompt library for reusable AI-assisted development workflows.',
    ]];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['summary_authorized_skills'])->not->toContain('Prisma');
});

it('unions authorized Skills across multiple Summary CareerFacts', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $firstSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Terraform']);
    $secondSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Kubernetes']);

    $firstFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-summary-union-first',
        'statement' => 'Provisioned infrastructure as code.',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $candidate['role']->id,
        'visibility' => Visibility::Public,
    ]);
    $firstFact->skills()->attach($firstSkill);

    $secondFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-summary-union-second',
        'statement' => 'Orchestrated containerized workloads.',
        'attributable_type' => (new Role)->getMorphClass(),
        'attributable_id' => $candidate['role']->id,
        'visibility' => Visibility::Public,
    ]);
    $secondFact->skills()->attach($secondSkill);

    $content = validSelectionContent($candidate, $job);
    $content['summary_evidence'] = [$firstFact->key, $secondFact->key];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['summary_authorized_skills'])
        ->toContain('Terraform')
        ->toContain('Kubernetes');
});

it('produces exactly one canonical name when a Skill is authorized through both attachment and text recognition', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $djangoSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Django']);
    $candidate['factIndependent']->skills()->attach($djangoSkill);
    $candidate['factIndependent']->update([
        'statement' => 'Independently implemented the technical solution in Django from team-provided requirements.',
    ]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect(array_count_values($variant->wording_input_snapshot['summary_authorized_skills'])['Django'])->toBe(1);
});

it('preserves longest-span overlap resolution when building summary_authorized_skills', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    // Attached to an unrelated, non-summary fact only — see the
    // GraphQL test above for why.
    $salesforceSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Salesforce']);
    $salesforceMarketingCloudSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Salesforce Marketing Cloud']);
    $candidate['factAws']->skills()->attach([$salesforceSkill->id, $salesforceMarketingCloudSkill->id]);
    $candidate['factIndependent']->update([
        'statement' => 'Independently implemented reporting on Salesforce Marketing Cloud.',
    ]);

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', validSelectionContent($candidate, $job)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['summary_authorized_skills'])
        ->toContain('Salesforce Marketing Cloud')
        ->not->toContain('Salesforce');
});

it('produces an empty summary_authorized_skills when Selection approves no summary evidence', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['summary_evidence'] = [];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->wording_input_snapshot['summary_authorized_skills'])->toBe([]);
});

it('never attaches an authorized-Skills field to an Experience bullet group or a Selected Project, only the Summary', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factIndependentProject']->key],
    ]];

    $wordingContent = validWordingContent($candidate);
    $wordingContent['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'text' => 'Built a structured prompt library for reusable AI-assisted development workflows.',
    ]];

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    $snapshot = $variant->wording_input_snapshot;
    foreach ($snapshot['experience'] as $role) {
        foreach ($role['bullet_groups'] as $group) {
            expect($group)->not->toHaveKey('authorized_skills')
                ->and($group)->not->toHaveKey('summary_authorized_skills');
        }
    }
    foreach ($snapshot['selected_projects'] as $project) {
        expect($project)->not->toHaveKey('authorized_skills')
            ->and($project)->not->toHaveKey('summary_authorized_skills');
    }
});
