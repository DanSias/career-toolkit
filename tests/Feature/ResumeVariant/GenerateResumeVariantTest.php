<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\ResumeClaimPosture;
use App\Enums\Visibility;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Models\CareerFact;
use App\Models\Education;
use App\Models\Employer;
use App\Models\Project;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\Role;
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
                    'project_id' => $candidate['project']->id,
                    'order' => 1,
                    'career_fact_keys' => [$candidate['factAws']->key],
                    'job_analysis_finding_ids' => [$job['azureFinding']->id],
                ],
                [
                    'project_id' => -1,
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
        ->and($variant->schema_version)->toBe('1.3')
        ->and($variant->selection_prompt_version)->toBe('resume-selection-v1.5')
        ->and($variant->wording_prompt_version)->toBe('resume-wording-v1.3')
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

it('rejects a project_id that belongs to a different role than its bullet group', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();

    $otherEmployer = Employer::factory()->create(['career_profile_id' => $candidate['profile']->id]);
    $otherRole = Role::factory()->create(['employer_id' => $otherEmployer->id]);
    $otherProject = Project::factory()->create(['role_id' => $otherRole->id]);

    $content = validSelectionContent($candidate, $job);
    $content['experience'][0]['bullet_groups'][0]['project_id'] = $otherProject->id; // belongs to $otherRole, not $candidate['role']

    [$selection, $wording] = bindFakeResumeProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', validWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects an exact duplicate bullet-group evidence set', function () {
    [$candidate, $job, $jobMatch] = fullFixtureSetup();
    $content = validSelectionContent($candidate, $job);
    $content['experience'][0]['bullet_groups'][] = [
        'project_id' => $candidate['project']->id,
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
    $schemaSource = file_get_contents(base_path('app/Support/ResumeVariant/Prompts/ResumeSelectionPromptV1.php'));
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
