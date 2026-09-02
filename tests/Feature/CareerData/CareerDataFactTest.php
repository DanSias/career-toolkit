<?php

use App\Enums\EvidenceSource;
use App\Enums\MetricComparator;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Evidence;
use App\Models\Metric;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use Inertia\Testing\AssertableInertia as Assert;

it('renders a career fact detail page successfully', function () {
    $fact = CareerFact::factory()->create();

    $this->get(route('career-data.facts.show', $fact))->assertOk();
});

it('returns a 404 for an unknown fact key', function () {
    $this->get('/career-data/facts/does-not-exist')->assertNotFound();
});

it('exposes verification separately from visibility, so a restricted fact can still be verified', function () {
    $fact = CareerFact::factory()->create([
        'verification' => Verification::Verified,
        'visibility' => Visibility::Restricted,
    ]);

    $this->get(route('career-data.facts.show', $fact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('fact.verification', 'verified')
            ->where('fact.visibility', 'restricted')
        );
});

it('resolves the full attribution breadcrumb for a project-attributed fact', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create(['name' => 'Acme Corp']);
    $role = Role::factory()->for($employer)->create(['title' => 'Engineer']);
    $project = Project::factory()->for($role)->create(['name' => 'Widget Platform']);

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    $this->get(route('career-data.facts.show', $fact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('fact.attribution.type', 'project')
            ->where('fact.attribution.path', ['Acme Corp', 'Engineer', 'Widget Platform'])
        );
});

it('keeps a bounded metric range intact rather than collapsing it', function () {
    $fact = CareerFact::factory()->create();
    Metric::factory()->for($fact)->create([
        'value' => 10,
        'value_max' => 15,
        'unit' => 'hours_per_month',
        'comparator' => MetricComparator::Approximately,
        'scope_note' => 'A range, not an exact figure.',
        'guardrail' => 'Do not narrow to a single number.',
    ]);

    $this->get(route('career-data.facts.show', $fact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('fact.metric.value', 10)
            ->where('fact.metric.value_max', 15)
            ->where('fact.metric.is_range', true)
            ->where('fact.metric.display', 'approximately 10–15 hours/month')
            ->where('fact.metric.scope_note', 'A range, not an exact figure.')
            ->where('fact.metric.guardrail', 'Do not narrow to a single number.')
        );
});

it('omits the metric entirely when a fact has none', function () {
    $fact = CareerFact::factory()->create();

    $this->get(route('career-data.facts.show', $fact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fact.metric', null));
});

it('reports no evidence recorded rather than breaking when a fact has none', function () {
    $fact = CareerFact::factory()->create();

    $this->get(route('career-data.facts.show', $fact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fact.evidence', []));
});

it('exposes evidence provenance fields when present', function () {
    $fact = CareerFact::factory()->create();
    Evidence::factory()->for($fact)->create([
        'source' => EvidenceSource::Portfolio,
        'path' => 'danielsias-dev/src/app/projects/example/page.tsx',
        'locator' => 'Results',
        'quoted_text' => 'A quoted excerpt.',
    ]);

    $this->get(route('career-data.facts.show', $fact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('fact.evidence', 1)
            ->where('fact.evidence.0.source', 'portfolio')
            ->where('fact.evidence.0.path', 'danielsias-dev/src/app/projects/example/page.tsx')
            ->where('fact.evidence.0.locator', 'Results')
            ->where('fact.evidence.0.quoted_text', 'A quoted excerpt.')
            ->where('fact.evidence.0.document', null)
        );
});

it('exposes associated skills on a fact', function () {
    $fact = CareerFact::factory()->create();
    $skill = Skill::factory()->create(['name' => 'PHP', 'category' => 'build_technology']);
    $fact->skills()->attach($skill);

    $this->get(route('career-data.facts.show', $fact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('fact.skills', 1)
            ->where('fact.skills.0.name', 'PHP')
        );
});
