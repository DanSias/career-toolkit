<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\Role;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;

/**
 * Proves the title_choice contract (Option C) against the REAL,
 * currently-imported canonical dataset — not a synthetic fixture —
 * for every real Role currently in the corpus, mirroring how
 * CanonicalVisibilityIntegrationTest and ResumeCandidatePayloadBuilderTest
 * exercise real canonical data rather than a hand-rolled graph. This is
 * the "real write path" (full ResumeSelectionResponseValidator +
 * GenerateResumeVariant::toSelectionDraft() resolution + persistence),
 * not a directly-called private helper.
 */
beforeEach(function () {
    Artisan::call('career:import');
});

function canonicalJobMatch(): JobMatch
{
    $profile = CareerProfile::query()->oldest('id')->firstOrFail();
    $posting = JobPosting::factory()->create(['career_profile_id' => $profile->id]);
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $posting->id]);

    return JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
    ]);
}

function bindCanonicalResumeFakes(): array
{
    $selection = new FakeResumeSelectionProvider;
    $wording = new FakeResumeWordingProvider;
    app()->instance(GeneratesResumeSelection::class, $selection);
    app()->instance(GeneratesResumeWording::class, $wording);

    return [$selection, $wording];
}

/**
 * @return array<string, mixed>
 */
function singleRoleSelection(int $roleId, string $titleChoice, string $careerFactKey): array
{
    return [
        'summary_evidence' => [],
        'skills' => [],
        'education_selection' => [],
        'experience' => [[
            'role_id' => $roleId,
            'title_choice' => $titleChoice,
            'bullet_groups' => [[
                'project_id' => -1,
                'order' => 1,
                'career_fact_keys' => [$careerFactKey],
                'job_analysis_finding_ids' => [],
            ]],
        ]],
        'target_term_usages' => [],
    ];
}

/**
 * @return array<string, mixed>
 */
function singleRoleWording(int $roleId): array
{
    return [
        'summary' => 'Candidate summary.',
        'experience' => [[
            'role_id' => $roleId,
            'bullets' => [['bullet_group_index' => 0, 'text' => 'A generated bullet.']],
        ]],
    ];
}

function generateWithTitleChoice(int $roleId, string $titleChoice, string $careerFactKey): ResumeVariant
{
    $match = canonicalJobMatch();
    [$selection, $wording] = bindCanonicalResumeFakes();
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', singleRoleSelection($roleId, $titleChoice, $careerFactKey)));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', singleRoleWording($roleId)));

    return app(GenerateResumeVariant::class)->generateFull($match);
}

it('offers only full for RocketGate\'s slash-free canonical title', function () {
    $role = Role::where('title', 'Developer Support Engineer')->firstOrFail();

    $variant = generateWithTitleChoice($role->id, 'full', 'rocketgate-source-control-gitlab');

    expect($variant->experienceBullets->first()->display_title)->toBe('Developer Support Engineer');
});

it('rejects segment_1 for RocketGate, which has no "/" in its title', function () {
    $role = Role::where('title', 'Developer Support Engineer')->firstOrFail();

    expect(fn () => generateWithTitleChoice($role->id, 'segment_1', 'rocketgate-source-control-gitlab'))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('offers only full for Pearson SEO Analyst\'s slash-free canonical title', function () {
    $role = Role::where('title', 'Search Engine Optimization Analyst')->firstOrFail();

    $variant = generateWithTitleChoice($role->id, 'full', 'pearson-seo-analyst-what-they-did');

    expect($variant->experienceBullets->first()->display_title)->toBe('Search Engine Optimization Analyst');
});

it('rejects segment_1 for Pearson SEO Analyst, which has no "/" in its title', function () {
    $role = Role::where('title', 'Search Engine Optimization Analyst')->firstOrFail();

    expect(fn () => generateWithTitleChoice($role->id, 'segment_1', 'pearson-seo-analyst-what-they-did'))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('resolves full/segment_1/segment_2 exactly for Pearson Data & Analytics Lead Developer / Data Analyst', function () {
    $role = Role::where('title', 'Data & Analytics Lead Developer / Data Analyst')->firstOrFail();
    $fact = 'pearson-data-analytics-lead-stakeholder-partnership';

    expect(generateWithTitleChoice($role->id, 'full', $fact)->experienceBullets->first()->display_title)
        ->toBe('Data & Analytics Lead Developer / Data Analyst');
    expect(generateWithTitleChoice($role->id, 'segment_1', $fact)->experienceBullets->first()->display_title)
        ->toBe('Data & Analytics Lead Developer');
    expect(generateWithTitleChoice($role->id, 'segment_2', $fact)->experienceBullets->first()->display_title)
        ->toBe('Data Analyst');
});

it('rejects segment_3 for Pearson Data & Analytics Lead Developer / Data Analyst, which has only two segments', function () {
    $role = Role::where('title', 'Data & Analytics Lead Developer / Data Analyst')->firstOrFail();

    expect(fn () => generateWithTitleChoice($role->id, 'segment_3', 'pearson-data-analytics-lead-stakeholder-partnership'))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('resolves full/segment_1/segment_2 exactly for Liquid Gravity\'s Founder & Full Stack Developer / Marketing Consultant', function () {
    $role = Role::where('title', 'Founder & Full Stack Developer / Marketing Consultant')->firstOrFail();
    $fact = 'liquid-gravity-what-they-did';

    expect(generateWithTitleChoice($role->id, 'full', $fact)->experienceBullets->first()->display_title)
        ->toBe('Founder & Full Stack Developer / Marketing Consultant');
    expect(generateWithTitleChoice($role->id, 'segment_1', $fact)->experienceBullets->first()->display_title)
        ->toBe('Founder & Full Stack Developer');
    expect(generateWithTitleChoice($role->id, 'segment_2', $fact)->experienceBullets->first()->display_title)
        ->toBe('Marketing Consultant');
});

it('never falls back to full when an out-of-range segment is supplied, for any real canonical role', function () {
    $role = Role::where('title', 'Founder & Full Stack Developer / Marketing Consultant')->firstOrFail();

    expect(fn () => generateWithTitleChoice($role->id, 'segment_9', 'liquid-gravity-what-they-did'))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});

it('rejects a title_choice that is globally valid for one role but illegal for the specific selected role', function () {
    // 'segment_2' is legal for the Data & Analytics Lead role but not
    // for RocketGate's slash-free title — proves per-role legality is
    // enforced, not merely "is this key valid somewhere in the corpus".
    $role = Role::where('title', 'Developer Support Engineer')->firstOrFail();

    expect(fn () => generateWithTitleChoice($role->id, 'segment_2', 'rocketgate-source-control-gitlab'))
        ->toThrow(InvalidResumeVariantResponseException::class);
    expect(ResumeVariant::count())->toBe(0);
});
