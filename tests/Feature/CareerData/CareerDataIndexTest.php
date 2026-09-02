<?php

use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the Career Data index successfully', function () {
    $this->get(route('career-data.index'))->assertOk();
});

it('shows a graceful empty state when no career profile exists', function () {
    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('career-data/index')
            ->where('profile', null)
            ->where('employers', [])
            ->where('education', [])
            ->where('skills', [])
        );
});

it('returns the real Employer -> Role -> Project hierarchy through the page-data shape', function () {
    $profile = CareerProfile::factory()->create(['name' => 'Test Person']);
    $employer = Employer::factory()->for($profile)->create(['name' => 'Acme Corp']);
    $role = Role::factory()->for($employer)->create([
        'title' => 'Engineer',
        'start_year' => 2022, 'start_month' => 3,
        'end_year' => null, 'end_month' => null,
    ]);
    $project = Project::factory()->for($role)->create(['name' => 'Widget Platform', 'slug' => 'widget-platform']);

    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('career-data/index')
            ->where('profile.name', 'Test Person')
            ->has('employers', 1)
            ->where('employers.0.name', 'Acme Corp')
            ->has('employers.0.roles', 1)
            ->where('employers.0.roles.0.title', 'Engineer')
            ->where('employers.0.roles.0.date_label', 'March 2022 – Present')
            ->where('employers.0.roles.0.is_current', true)
            ->has('employers.0.roles.0.projects', 1)
            ->where('employers.0.roles.0.projects.0.name', 'Widget Platform')
        );
});

it('handles a role with no projects gracefully', function () {
    $role = Role::factory()->create();

    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employers.0.roles.0.projects', [])
        );
});

it('handles an employer with no roles gracefully', function () {
    Employer::factory()->create();

    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employers.0.roles', [])
        );
});

it('preserves distinct public, restricted, and private visibility values on projects', function () {
    // All three under the same Role, matching this app's single-CareerProfile
    // assumption — three independent Project::factory() calls would each spin
    // up their own CareerProfile, and the index page only ever shows one.
    $role = Role::factory()->create();
    $public = Project::factory()->for($role)->create(['default_visibility' => Visibility::Public->value]);
    $restricted = Project::factory()->for($role)->create(['default_visibility' => Visibility::Restricted->value]);
    $private = Project::factory()->for($role)->create(['default_visibility' => Visibility::Private->value]);

    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($public, $restricted, $private) {
            $page->has('employers.0.roles.0.projects', 3);
            $slugs = collect($page->toArray()['props']['employers'][0]['roles'][0]['projects'])->keyBy('slug');

            expect($slugs[$public->slug]['visibility'])->toBe('public')
                ->and($slugs[$restricted->slug]['visibility'])->toBe('restricted')
                ->and($slugs[$private->slug]['visibility'])->toBe('private');
        });
});

it('does not populate project skills, and the page still renders correctly', function () {
    $project = Project::factory()->create();

    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employers.0.roles.0.projects.0.skills', [])
        );

    expect($project->skills)->toHaveCount(0);
});

it('shows career-wide facts attributed directly to the profile', function () {
    $profile = CareerProfile::factory()->create();
    $fact = CareerFact::factory()->for($profile)->create([
        'statement' => 'A career-wide claim.',
        'verification' => Verification::Verified,
        'visibility' => Visibility::Public,
        'attributable_type' => $profile->getMorphClass(),
        'attributable_id' => $profile->id,
    ]);

    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('profile.facts', 1)
            ->where('profile.facts.0.key', $fact->key)
            ->where('profile.facts.0.statement', 'A career-wide claim.')
            ->where('profile.facts.0.verification', 'verified')
            ->where('profile.facts.0.visibility', 'public')
        );
});

it('includes education records with supported year information only', function () {
    $profile = CareerProfile::factory()->create();
    Education::factory()->for($profile)->create([
        'institution' => 'State University',
        'degree' => 'Bachelor of Science',
        'field_of_study' => 'Computer Science',
        'start_year' => null,
        'end_year' => 2010,
    ]);

    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('education', 1)
            ->where('education.0.institution', 'State University')
            ->where('education.0.start_year', null)
            ->where('education.0.end_year', 2010)
        );
});

it('includes skills with zero associations rather than hiding them', function () {
    $skill = Skill::factory()->create(['name' => 'Unused Skill']);

    $this->get(route('career-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('skills.0.slug', $skill->slug)
            ->where('skills.0.career_fact_count', 0)
            ->where('skills.0.project_count', 0)
        );
});

it('exposes no write routes for career data', function () {
    $writeMethods = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\CareerDataController'))
        ->flatMap(fn ($route) => $route->methods())
        ->reject(fn (string $method) => in_array($method, ['GET', 'HEAD'], true));

    expect($writeMethods)->toBeEmpty();
});
