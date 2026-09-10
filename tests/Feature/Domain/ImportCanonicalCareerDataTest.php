<?php

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Employer;
use App\Models\Evidence;
use App\Models\Metric;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A minimal, self-contained dataset shaped exactly like
 * data/canonical-career-data.proposed.json, used only for exercising the
 * importer — never the real canonical data.
 *
 * @return array<string, mixed>
 */
function validImportDataset(): array
{
    return [
        'career_profile' => ['key' => 'test-profile', 'name' => 'Test Person', 'email' => 'test-person@example.com', 'phone' => '(407) 272-1720'],
        'employers' => [
            ['key' => 'acme', 'name' => 'Acme Corp', 'short_name' => null, 'description' => null],
        ],
        'roles' => [
            [
                'key' => 'acme-engineer',
                'employer_key' => 'acme',
                'title' => 'Engineer',
                'start_year' => 2020, 'start_month' => 3,
                'end_year' => null, 'end_month' => null,
                'sort_order' => 0, 'summary' => null,
            ],
        ],
        'projects' => [
            [
                'key' => 'widget-platform',
                'role_key' => 'acme-engineer',
                'name' => 'Widget Platform',
                'description' => null,
                'default_visibility' => 'public',
                'sort_order' => 0,
            ],
        ],
        'skills' => [
            ['key' => 'php', 'name' => 'PHP', 'category' => 'build_technology', 'description' => null],
            ['key' => 'testing', 'name' => 'Testing', 'category' => 'practice', 'description' => null],
        ],
        'educations' => [
            [
                'institution' => 'State University', 'degree' => 'Bachelor of Science',
                'field_of_study' => 'Computer Science', 'start_year' => null, 'end_year' => 2010, 'sort_order' => 0,
            ],
        ],
        'career_facts' => [
            [
                'key' => 'profile-fact',
                'fact_type' => 'narrative',
                'statement' => 'Career-wide fact.',
                'verification' => 'verified',
                'visibility' => 'public',
                'notes' => null,
                'attributable' => ['type' => 'career_profile', 'key' => 'test-profile'],
                'evidence' => [
                    ['source' => 'user_confirmed', 'confirmed_at' => '2026-01-01', 'note' => 'confirmed'],
                ],
                'metric' => null,
                'skills' => [],
            ],
            [
                'key' => 'employer-fact',
                'fact_type' => 'bullet',
                'statement' => 'Employer-level fact.',
                'verification' => 'strongly_supported',
                'visibility' => 'public',
                'notes' => null,
                'attributable' => ['type' => 'employer', 'key' => 'acme'],
                'evidence' => [],
                'metric' => null,
                'skills' => [],
            ],
            [
                'key' => 'role-fact',
                'fact_type' => 'capability',
                'statement' => 'Role-level fact.',
                'verification' => 'verified',
                'visibility' => 'public',
                'notes' => null,
                'attributable' => ['type' => 'role', 'key' => 'acme-engineer'],
                'evidence' => [],
                'metric' => null,
                'skills' => ['testing'],
            ],
            [
                'key' => 'project-fact-with-range-metric',
                'fact_type' => 'metric',
                'statement' => 'Saved 10-15 hours per month.',
                'verification' => 'verified',
                'visibility' => 'public',
                'notes' => null,
                'attributable' => ['type' => 'project', 'key' => 'widget-platform'],
                'evidence' => [
                    ['source' => 'resume', 'document' => 'resume.pdf', 'section' => 'Acme', 'quoted_text' => 'saved time'],
                ],
                'metric' => [
                    'value' => 10, 'value_max' => 15, 'unit' => 'hours_per_month',
                    'comparator' => 'approximately', 'scope_note' => 'a range', 'guardrail' => 'do not narrow',
                ],
                'skills' => ['php'],
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $dataset
 */
function writeImportFixture(array $dataset): string
{
    $path = sys_get_temp_dir().'/career-import-test-'.uniqid().'.json';
    file_put_contents($path, json_encode($dataset, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/career-import-test-*.json') as $file) {
        @unlink($file);
    }
});

it('creates the expected records on first import', function () {
    $path = writeImportFixture(validImportDataset());

    Artisan::call('career:import', ['path' => $path]);

    expect(User::count())->toBe(1)
        ->and(CareerProfile::count())->toBe(1)
        ->and(Employer::count())->toBe(1)
        ->and(Role::count())->toBe(1)
        ->and(Project::count())->toBe(1)
        ->and(Skill::count())->toBe(2)
        ->and(Education::count())->toBe(1)
        ->and(CareerFact::count())->toBe(4)
        ->and(Evidence::count())->toBe(2)
        ->and(Metric::count())->toBe(1);

    $metric = CareerFact::where('key', 'project-fact-with-range-metric')->firstOrFail()->metric;
    expect($metric->value)->toEqual('10.00')
        ->and($metric->value_max)->toEqual('15.00')
        ->and($metric->guardrail)->toBe('do not narrow');
});

it('does not duplicate any record on a second import', function () {
    $path = writeImportFixture(validImportDataset());

    Artisan::call('career:import', ['path' => $path]);
    Artisan::call('career:import', ['path' => $path]);

    expect(User::count())->toBe(1)
        ->and(CareerProfile::count())->toBe(1)
        ->and(Employer::count())->toBe(1)
        ->and(Role::count())->toBe(1)
        ->and(Project::count())->toBe(1)
        ->and(Skill::count())->toBe(2)
        ->and(Education::count())->toBe(1)
        ->and(CareerFact::count())->toBe(4)
        ->and(Evidence::count())->toBe(2)
        ->and(Metric::count())->toBe(1)
        ->and(DB::table('career_fact_skill')->count())->toBe(2);
});

it('imports the canonical phone number onto CareerProfile using the same normalized human-readable string convention as other contact fields', function () {
    $path = writeImportFixture(validImportDataset());

    Artisan::call('career:import', ['path' => $path]);

    $profile = CareerProfile::where('email', 'test-person@example.com')->firstOrFail();
    expect($profile->phone)->toBe('(407) 272-1720');
});

it('does not duplicate or alter the CareerProfile phone number on a second import', function () {
    $path = writeImportFixture(validImportDataset());

    Artisan::call('career:import', ['path' => $path]);
    Artisan::call('career:import', ['path' => $path]);

    expect(CareerProfile::count())->toBe(1);
    $profile = CareerProfile::where('email', 'test-person@example.com')->firstOrFail();
    expect($profile->phone)->toBe('(407) 272-1720');
});

it('imports a null phone as null rather than inventing a value, when the canonical source has none', function () {
    $dataset = validImportDataset();
    unset($dataset['career_profile']['phone']);
    $path = writeImportFixture($dataset);

    Artisan::call('career:import', ['path' => $path]);

    $profile = CareerProfile::where('email', 'test-person@example.com')->firstOrFail();
    expect($profile->phone)->toBeNull();
});

it('deterministically applies edited canonical wording on re-import', function () {
    $dataset = validImportDataset();
    $path = writeImportFixture($dataset);
    Artisan::call('career:import', ['path' => $path]);

    $dataset['career_facts'][1]['statement'] = 'Updated employer-level fact statement.';
    $dataset['career_facts'][1]['verification'] = 'needs_confirmation';
    file_put_contents($path, json_encode($dataset));

    Artisan::call('career:import', ['path' => $path]);

    $fact = CareerFact::where('key', 'employer-fact')->firstOrFail();
    expect($fact->statement)->toBe('Updated employer-level fact statement.')
        ->and($fact->verification->value)->toBe('needs_confirmation')
        ->and(CareerFact::count())->toBe(4);
});

it('replaces evidence deterministically rather than accumulating it', function () {
    $dataset = validImportDataset();
    $path = writeImportFixture($dataset);
    Artisan::call('career:import', ['path' => $path]);

    $dataset['career_facts'][0]['evidence'] = [
        ['source' => 'user_confirmed', 'confirmed_at' => '2026-02-01', 'note' => 'updated confirmation'],
        ['source' => 'resume', 'document' => 'resume.pdf', 'section' => 'Summary'],
    ];
    file_put_contents($path, json_encode($dataset));

    Artisan::call('career:import', ['path' => $path]);

    $fact = CareerFact::where('key', 'profile-fact')->firstOrFail();
    expect($fact->evidence)->toHaveCount(2)
        ->and($fact->evidence->pluck('note')->filter()->first())->toBe('updated confirmation');
});

it('fails loudly and rolls back when a career fact references an unresolved attributable key', function () {
    $dataset = validImportDataset();
    $dataset['career_facts'][] = [
        'key' => 'broken-fact',
        'fact_type' => 'bullet',
        'statement' => 'Broken.',
        'verification' => 'verified',
        'visibility' => 'public',
        'notes' => null,
        'attributable' => ['type' => 'project', 'key' => 'does-not-exist'],
        'evidence' => [],
        'metric' => null,
        'skills' => [],
    ];
    $path = writeImportFixture($dataset);

    $exitCode = Artisan::call('career:import', ['path' => $path]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('does-not-exist')
        // The whole run rolled back — not even the valid facts persisted.
        ->and(CareerFact::count())->toBe(0)
        ->and(Employer::count())->toBe(0);
});

it('fails loudly when a role references an unresolved employer key', function () {
    $dataset = validImportDataset();
    $dataset['roles'][0]['employer_key'] = 'does-not-exist';
    $path = writeImportFixture($dataset);

    $exitCode = Artisan::call('career:import', ['path' => $path]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('does-not-exist')
        ->and(Employer::count())->toBe(0);
});

it('fails loudly on a duplicate role natural key instead of silently merging', function () {
    $dataset = validImportDataset();
    $dataset['roles'][] = [
        'key' => 'acme-engineer-duplicate',
        'employer_key' => 'acme',
        'title' => 'Engineer',
        'start_year' => 2020, 'start_month' => 3,
        'end_year' => null, 'end_month' => null,
        'sort_order' => 0, 'summary' => null,
    ];
    $path = writeImportFixture($dataset);

    $exitCode = Artisan::call('career:import', ['path' => $path]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('Duplicate role')
        ->and(Role::count())->toBe(0);
});

it('fails loudly on a duplicate career fact key', function () {
    $dataset = validImportDataset();
    $duplicate = $dataset['career_facts'][0];
    $duplicate['statement'] = 'A different statement, same key.';
    $dataset['career_facts'][] = $duplicate;
    $path = writeImportFixture($dataset);

    $exitCode = Artisan::call('career:import', ['path' => $path]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('Duplicate career fact');
});

it('preserves attribution integrity: refuses a cross-profile-style unresolved reference', function () {
    $dataset = validImportDataset();
    $dataset['career_facts'][0]['attributable'] = ['type' => 'role', 'key' => 'nonexistent-role'];
    $path = writeImportFixture($dataset);

    $exitCode = Artisan::call('career:import', ['path' => $path]);

    expect($exitCode)->not->toBe(0)
        ->and(CareerFact::count())->toBe(0);
});

it('enforces Role date-range integrity through the same saving hook during import', function () {
    $dataset = validImportDataset();
    $dataset['roles'][0]['end_year'] = 2019; // before start_year 2020
    $path = writeImportFixture($dataset);

    $exitCode = Artisan::call('career:import', ['path' => $path]);

    expect($exitCode)->not->toBe(0)
        ->and(Role::count())->toBe(0);
});

it('enforces Metric range integrity through the same saving hook during import', function () {
    $dataset = validImportDataset();
    $dataset['career_facts'][3]['metric']['value_max'] = 5; // less than value (10)
    $path = writeImportFixture($dataset);

    $exitCode = Artisan::call('career:import', ['path' => $path]);

    expect($exitCode)->not->toBe(0)
        ->and(Metric::count())->toBe(0);
});

it('fails loudly on a nonexistent dataset file', function () {
    $exitCode = Artisan::call('career:import', ['path' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json']);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('not found');
});
