<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\Visibility;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Project;
use App\Models\ResumeVariant;
use App\Models\Skill;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;
use Tests\Support\ResumeVariantFixtures;

/**
 * Selected Projects: independent (role_id-null) Projects only, chosen
 * 0-3 by Selection, worded by Wording as exactly one bullet each, and
 * frozen onto ResumeVariantProject/ResumeVariantProjectBullet/
 * ResumeVariantProjectBulletCitation. See docs/domain-model.md
 * "ResumeVariant" -> "Selected Projects".
 */
function bindSelectedProjectsProviders(): array
{
    $selection = new FakeResumeSelectionProvider;
    $wording = new FakeResumeWordingProvider;
    app()->instance(GeneratesResumeSelection::class, $selection);
    app()->instance(GeneratesResumeWording::class, $wording);

    return [$selection, $wording];
}

/**
 * A minimal, valid Selection response representing only the candidate's
 * professional Role — Selected Projects/target-term arrays are empty
 * unless the caller overrides them, so each test only needs to
 * describe what it actually cares about.
 *
 * @return array<string, mixed>
 */
function baseSelectionContent(array $candidate): array
{
    return [
        'summary_evidence' => [],
        'skills' => [],
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'title_choice' => 'full',
            'bullet_groups' => [[
                'project_id' => -1,
                'order' => 1,
                'career_fact_keys' => [$candidate['factIndependent']->key],
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
function baseWordingContent(array $candidate): array
{
    return [
        'summary' => 'Candidate summary.',
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'bullets' => [['bullet_group_index' => 0, 'text' => 'Independently implemented the technical solution from team-provided requirements.']],
        ]],
        'selected_projects' => [],
    ];
}

function selectedProjectsFixtureSetup(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $jobMatch = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    return [$candidate, $job, $jobMatch];
}

it('persists nothing when Selection selects zero Projects — a completely normal outcome', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();
    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', baseSelectionContent($candidate)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->projects)->toHaveCount(0);
});

it('persists one selected Project with its one frozen bullet and citation', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();
    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factIndependentProject']->key],
    ]];

    $wordingContent = baseWordingContent($candidate);
    $wordingContent['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'text' => 'Built a structured prompt library for reusable AI-assisted development workflows.',
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->projects)->toHaveCount(1);
    $project = $variant->projects->first();
    expect($project->project_id)->toBe($candidate['independentProject']->id)
        ->and($project->name)->toBe('Well Prompted')
        ->and($project->technology_names)->toBe(['Prisma'])
        ->and($project->live_url)->toBe('https://wellprompted.example.dev')
        ->and($project->repository_url)->toBe('https://github.com/example/well-prompted')
        ->and($project->display_order)->toBe(0)
        ->and($project->bullets)->toHaveCount(1);

    $bullet = $project->bullets->first();
    expect($bullet->text)->toBe('Built a structured prompt library for reusable AI-assisted development workflows.')
        ->and($bullet->citations)->toHaveCount(1)
        ->and($bullet->citations->first()->career_fact_id)->toBe($candidate['factIndependentProject']->id);
});

it('persists three selected Projects, preserving Selection relevance order', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();

    $projectB = Project::factory()->create([
        'role_id' => null,
        'career_profile_id' => $candidate['profile']->id,
        'name' => 'PromptWorks',
    ]);
    $factB = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-promptworks-what-it-is',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $projectB->id,
        'visibility' => Visibility::Public,
    ]);

    $projectC = Project::factory()->create([
        'role_id' => null,
        'career_profile_id' => $candidate['profile']->id,
        'name' => 'Well Applied',
    ]);
    $factC = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-well-applied-what-it-is',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $projectC->id,
        'visibility' => Visibility::Public,
    ]);

    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [
        ['project_id' => $projectC->id, 'order' => 1, 'career_fact_keys' => [$factC->key]],
        ['project_id' => $candidate['independentProject']->id, 'order' => 2, 'career_fact_keys' => [$candidate['factIndependentProject']->key]],
        ['project_id' => $projectB->id, 'order' => 3, 'career_fact_keys' => [$factB->key]],
    ];

    $wordingContent = baseWordingContent($candidate);
    $wordingContent['selected_projects'] = [
        ['project_id' => $projectC->id, 'text' => 'Well Applied bullet.'],
        ['project_id' => $candidate['independentProject']->id, 'text' => 'Well Prompted bullet.'],
        ['project_id' => $projectB->id, 'text' => 'PromptWorks bullet.'],
    ];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->projects)->toHaveCount(3)
        ->and($variant->projects->sortBy('display_order')->pluck('name')->all())
        ->toBe(['Well Applied', 'Well Prompted', 'PromptWorks']);
});

it('rejects a fourth selected Project — max 3 — and persists nothing', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();

    $extraProjects = collect(range(1, 3))->map(function (int $i) use ($candidate) {
        $project = Project::factory()->create([
            'role_id' => null,
            'career_profile_id' => $candidate['profile']->id,
            'name' => "Extra Project {$i}",
        ]);
        $fact = CareerFact::factory()->create([
            'career_profile_id' => $candidate['profile']->id,
            'key' => "fixture-extra-{$i}",
            'attributable_type' => (new Project)->getMorphClass(),
            'attributable_id' => $project->id,
            'visibility' => Visibility::Public,
        ]);

        return [$project, $fact];
    });

    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [
        ['project_id' => $candidate['independentProject']->id, 'order' => 1, 'career_fact_keys' => [$candidate['factIndependentProject']->key]],
        ...$extraProjects->map(fn (array $pair) => ['project_id' => $pair[0]->id, 'order' => 2, 'career_fact_keys' => [$pair[1]->key]])->all(),
    ];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a professional Project id in selected_projects, and persists nothing', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();
    $content = baseSelectionContent($candidate);
    // $candidate['project'] is a professional project, owned by a Role.
    $content['selected_projects'] = [[
        'project_id' => $candidate['project']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factAws']->key],
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('never offers a Private-default-visibility independent Project as a legal selection, and rejects it if forced', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();

    $privateProject = Project::factory()->create([
        'role_id' => null,
        'career_profile_id' => $candidate['profile']->id,
        'name' => 'Private Side Project',
        'default_visibility' => Visibility::Private,
    ]);
    $privateFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-private-project-fact',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $privateProject->id,
        'visibility' => Visibility::Public, // fact itself public — the Project backstop must still exclude it.
    ]);

    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [[
        'project_id' => $privateProject->id,
        'order' => 1,
        'career_fact_keys' => [$privateFact->key],
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a cross-profile Project id in selected_projects, and persists nothing', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();

    $otherProfile = CareerProfile::factory()->create();
    $otherProject = Project::factory()->create(['role_id' => null, 'career_profile_id' => $otherProfile->id]);
    $otherFact = CareerFact::factory()->create([
        'career_profile_id' => $otherProfile->id,
        'key' => 'fixture-other-profile-project-fact',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $otherProject->id,
        'visibility' => Visibility::Public,
    ]);

    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [[
        'project_id' => $otherProject->id,
        'order' => 1,
        'career_fact_keys' => [$otherFact->key],
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a selected_projects entry citing a CareerFact not actually attributed to that Project, and persists nothing', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();
    $content = baseSelectionContent($candidate);
    // factAws is real, eligible, and supplied — but attributed to the
    // professional project, not to independentProject.
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factAws']->key],
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a selected_projects entry with zero cited CareerFacts, and persists nothing', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();
    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [],
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a duplicate Project selected twice, and persists nothing', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();
    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [
        ['project_id' => $candidate['independentProject']->id, 'order' => 1, 'career_fact_keys' => [$candidate['factIndependentProject']->key]],
        ['project_id' => $candidate['independentProject']->id, 'order' => 2, 'career_fact_keys' => [$candidate['factIndependentProject']->key]],
    ];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects Wording omitting an approved Selected Project, and persists nothing', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();
    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factIndependentProject']->key],
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    // Wording's own selected_projects stays empty — never wording the approved project.
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', baseWordingContent($candidate)));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects Wording inventing a Selected Project Selection never approved, and persists nothing', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();

    $wordingContent = baseWordingContent($candidate);
    $wordingContent['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'text' => 'Invented project bullet Selection never approved.',
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    // Selection's own selected_projects stays empty.
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', baseSelectionContent($candidate)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a Wording project bullet containing a denylisted target term, and persists nothing', function () {
    [$candidate, $job, $jobMatch] = selectedProjectsFixtureSetup();
    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factIndependentProject']->key],
    ]];

    $wordingContent = baseWordingContent($candidate);
    $wordingContent['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'text' => 'Built with Azure and other modern tools.', // "Azure" is this fixture's target term.
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    expect(fn () => app(GenerateResumeVariant::class)->generateFull($jobMatch))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('derives technology_names deterministically from only the cited facts\' attached Skills, in first-appearance order, never from Project::derivedSkills()', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();

    // A second Skill attached to the SAME project via a DIFFERENT
    // fact that is NOT cited — must not appear in technology_names.
    $uncitedSkill = Skill::factory()->create(['career_profile_id' => $candidate['profile']->id, 'name' => 'Supabase']);
    $uncitedFact = CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'key' => 'fixture-well-prompted-uncited',
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $candidate['independentProject']->id,
        'visibility' => Visibility::Public,
    ]);
    $uncitedFact->skills()->attach($uncitedSkill);

    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factIndependentProject']->key], // only the Prisma-tagged fact.
    ]];

    $wordingContent = baseWordingContent($candidate);
    $wordingContent['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'text' => 'Built a structured prompt library.',
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    expect($variant->projects->first()->technology_names)->toBe(['Prisma'])
        ->and($variant->projects->first()->technology_names)->not->toContain('Supabase');
});

it('freezes ResumeVariantProject content, immune to later canonical Project/Skill edits', function () {
    [$candidate, , $jobMatch] = selectedProjectsFixtureSetup();
    $content = baseSelectionContent($candidate);
    $content['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'order' => 1,
        'career_fact_keys' => [$candidate['factIndependentProject']->key],
    ]];

    $wordingContent = baseWordingContent($candidate);
    $wordingContent['selected_projects'] = [[
        'project_id' => $candidate['independentProject']->id,
        'text' => 'Built a structured prompt library.',
    ]];

    [$selection, $wording] = bindSelectedProjectsProviders();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', $content));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', $wordingContent));

    $variant = app(GenerateResumeVariant::class)->generateFull($jobMatch);

    $candidate['independentProject']->update(['name' => 'Renamed Project', 'live_url' => 'https://renamed.example.dev']);
    $candidate['independentProjectSkill']->update(['name' => 'Renamed Skill']);

    $variant->refresh();
    $project = $variant->projects()->with('bullets')->first();

    expect($project->name)->toBe('Well Prompted')
        ->and($project->name)->not->toBe('Renamed Project')
        ->and($project->live_url)->toBe('https://wellprompted.example.dev')
        ->and($project->technology_names)->toBe(['Prisma'])
        ->and($project->technology_names)->not->toContain('Renamed Skill');
});
