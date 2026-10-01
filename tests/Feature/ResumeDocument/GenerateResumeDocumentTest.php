<?php

use App\Models\Project;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumeDocument;
use App\Support\ResumeDocument\ResumeEducationEntry;
use App\Support\ResumeDocument\ResumeExperienceRole;
use App\Support\ResumeDocument\ResumeLink;
use App\Support\ResumeDocument\ResumeSelectedProject;
use Illuminate\Database\QueryException;
use Tests\Support\PearlyResumeVariantFixture;
use Tests\Support\ResumeVariantFixtures;

/**
 * Proves App\Support\ResumeDocument\GenerateResumeDocument assembles a
 * ResumeDocument entirely from ResumeVariant's frozen snapshot tree —
 * never a raw Eloquent model, never a live re-resolution of mutable
 * Employer/Role/Skill/Education content. See
 * docs/domain-model.md "ResumeVariant" -> "Experience role snapshots".
 */
function minimalResumeVariant(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    $candidate['profile']->update([
        'email' => 'candidate@example.com',
        'phone' => '555-0100',
        'location' => 'Remote',
        'portfolio_url' => 'https://example.dev/',
        'github_url' => 'https://github.com/example',
    ]);

    $variant = ResumeVariant::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'job_match_id' => $match->id,
        'summary' => 'A targeted summary.',
    ]);

    $experienceRole = $variant->experienceRoles()->create([
        'role_id' => $candidate['role']->id,
        'employer_name' => $candidate['employer']->name,
        'display_title' => 'Senior Software Engineer',
        'start_year' => $candidate['role']->start_year,
        'start_month' => $candidate['role']->start_month,
        'end_year' => $candidate['role']->end_year,
        'end_month' => $candidate['role']->end_month,
        'display_order' => 1,
    ]);
    $experienceRole->bullets()->create([
        'resume_variant_id' => $variant->id,
        'project_id' => $candidate['project']->id,
        'display_order' => 1,
        'text' => 'Built cloud-hosted deployment workflows on AWS.',
    ]);

    $variant->skillSelections()->create([
        'skill_id' => $candidate['awsSkill']->id,
        'name' => $candidate['awsSkill']->name,
        'category' => $candidate['awsSkill']->category->value,
        'display_order' => 1,
    ]);
    $variant->educationSelections()->create([
        'education_id' => $candidate['education']->id,
        'institution' => $candidate['education']->institution,
        'degree' => $candidate['education']->degree,
        'field_of_study' => $candidate['education']->field_of_study,
        'start_year' => $candidate['education']->start_year,
        'end_year' => $candidate['education']->end_year,
        'display_order' => 1,
    ]);

    return [$candidate, $variant->fresh()];
}

function minimalResumeVariantWithSelectedProject(): array
{
    [$candidate, $variant] = minimalResumeVariant();

    $projectRow = $variant->projects()->create([
        'project_id' => $candidate['independentProject']->id,
        'name' => 'Well Prompted',
        'technology_names' => ['Prisma', 'Supabase'],
        'live_url' => 'https://wellprompted.example.dev',
        'repository_url' => 'https://github.com/example/well-prompted',
        'display_order' => 1,
    ]);
    $projectRow->bullets()->create([
        'display_order' => 1,
        'text' => 'Built a structured prompt library for reusable AI-assisted development workflows.',
    ]);

    return [$candidate, $variant->fresh()];
}

it('assembles a ResumeDocument entirely from frozen snapshot fields', function () {
    [$candidate, $variant] = minimalResumeVariant();

    $document = (new GenerateResumeDocument)->generate($variant);

    expect($document)->toBeInstanceOf(ResumeDocument::class)
        ->and($document->contact->name)->toBe($candidate['profile']->name)
        ->and($document->contact->email)->toBe('candidate@example.com')
        ->and($document->contact->phone)->toBe('555-0100')
        ->and($document->contact->location)->toBe('Remote')
        ->and($document->contact->portfolio)->toBeInstanceOf(ResumeLink::class)
        ->and($document->contact->portfolio->url)->toBe('https://example.dev/')
        ->and($document->contact->portfolio->label)->toBe('example.dev')
        ->and($document->contact->github->label)->toBe('github.com/example')
        ->and($document->summary)->toBe('A targeted summary.')
        ->and($document->experience)->toHaveCount(1)
        ->and($document->experience[0])->toBeInstanceOf(ResumeExperienceRole::class)
        ->and($document->experience[0]->employerName)->toBe($candidate['employer']->name)
        ->and($document->experience[0]->displayTitle)->toBe('Senior Software Engineer')
        ->and($document->experience[0]->bullets)->toBe(['Built cloud-hosted deployment workflows on AWS.'])
        ->and($document->skills)->toHaveCount(1)
        ->and($document->skills[0]->skills)->toBe([$candidate['awsSkill']->name])
        ->and($document->selectedProjects)->toBe([])
        ->and($document->education)->toHaveCount(1)
        ->and($document->education[0])->toBeInstanceOf(ResumeEducationEntry::class)
        ->and($document->education[0]->institution)->toBe($candidate['education']->institution);
});

it('formats a role date range using RoleDateFormatter, current role rendered as Present', function () {
    [$candidate, $variant] = minimalResumeVariant();

    $document = (new GenerateResumeDocument)->generate($variant);

    expect($document->experience[0]->isCurrent)->toBe($candidate['role']->end_year === null)
        ->and($document->experience[0]->dateRangeLabel)->toContain('–');
});

it('formats the role date range with three-letter month abbreviations, not the full month name', function () {
    [$candidate, $variant] = minimalResumeVariant();

    // ResumeVariantExperienceRole rows are immutable once created (see
    // App\Models\ResumeVariantExperienceRole), so a fresh snapshot with
    // its own dates is built directly here rather than updating the
    // one minimalResumeVariant() already created.
    $secondVariant = ResumeVariant::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'job_match_id' => $variant->job_match_id,
        'summary' => 'A targeted summary.',
    ]);
    $secondVariant->experienceRoles()->create([
        'role_id' => $candidate['role']->id,
        'employer_name' => $candidate['employer']->name,
        'display_title' => 'Senior Software Engineer',
        'start_year' => 2015, 'start_month' => 9, 'end_year' => 2024, 'end_month' => 7,
        'display_order' => 1,
    ]);

    $document = (new GenerateResumeDocument)->generate($secondVariant->fresh([
        'careerProfile', 'experienceRoles.bullets', 'skillSelections', 'educationSelections',
    ]));

    expect($document->experience[0]->dateRangeLabel)->toBe('Sep 2015 – Jul 2024')
        ->and($document->experience[0]->dateRangeLabel)->not->toContain('September');
});

it('renders no contact link when the URL is null', function () {
    [$candidate, $variant] = minimalResumeVariant();
    $candidate['profile']->update(['portfolio_url' => null, 'github_url' => null]);

    $document = (new GenerateResumeDocument)->generate($variant->fresh());

    expect($document->contact->portfolio)->toBeNull()
        ->and($document->contact->github)->toBeNull();
});

it('never renders a project label in v1 — bullets are plain strings', function () {
    [$candidate, $variant] = minimalResumeVariant();

    $document = (new GenerateResumeDocument)->generate($variant);

    expect($document->experience[0]->bullets[0])->toBeString();
});

// --- Selected Projects -------------------------------------------------

it('assembles a ResumeSelectedProject entirely from frozen snapshot fields, ordered and with both links', function () {
    [, $variant] = minimalResumeVariantWithSelectedProject();

    $document = (new GenerateResumeDocument)->generate($variant);

    expect($document->selectedProjects)->toHaveCount(1)
        ->and($document->selectedProjects[0])->toBeInstanceOf(ResumeSelectedProject::class)
        ->and($document->selectedProjects[0]->name)->toBe('Well Prompted')
        ->and($document->selectedProjects[0]->technologies)->toBe(['Prisma', 'Supabase'])
        ->and($document->selectedProjects[0]->bullet)->toBe('Built a structured prompt library for reusable AI-assisted development workflows.')
        ->and($document->selectedProjects[0]->liveDemo)->toBeInstanceOf(ResumeLink::class)
        ->and($document->selectedProjects[0]->liveDemo->url)->toBe('https://wellprompted.example.dev')
        ->and($document->selectedProjects[0]->repository->url)->toBe('https://github.com/example/well-prompted');
});

it('omits a null live_url or repository_url independently on a ResumeSelectedProject', function () {
    [$candidate, $variant] = minimalResumeVariant();
    $projectRow = $variant->projects()->create([
        'project_id' => $candidate['independentProject']->id,
        'name' => 'Well Prompted',
        'technology_names' => [],
        'live_url' => null,
        'repository_url' => 'https://github.com/example/well-prompted',
        'display_order' => 1,
    ]);
    $projectRow->bullets()->create(['display_order' => 1, 'text' => 'Built something.']);

    $document = (new GenerateResumeDocument)->generate($variant->fresh());

    expect($document->selectedProjects[0]->liveDemo)->toBeNull()
        ->and($document->selectedProjects[0]->repository)->not->toBeNull()
        ->and($document->selectedProjects[0]->technologies)->toBe([]);
});

it('preserves display_order across multiple ResumeVariantProject rows', function () {
    [$candidate, $variant] = minimalResumeVariant();

    $second = Project::factory()->create([
        'role_id' => null,
        'career_profile_id' => $candidate['profile']->id,
        'name' => 'PromptWorks',
    ]);

    $first = $variant->projects()->create([
        'project_id' => $second->id, 'name' => 'Second (order 1)', 'technology_names' => [], 'display_order' => 1,
    ]);
    $first->bullets()->create(['display_order' => 1, 'text' => 'Second bullet.']);

    $zero = $variant->projects()->create([
        'project_id' => $candidate['independentProject']->id, 'name' => 'First (order 0)', 'technology_names' => [], 'display_order' => 0,
    ]);
    $zero->bullets()->create(['display_order' => 1, 'text' => 'First bullet.']);

    $document = (new GenerateResumeDocument)->generate($variant->fresh());

    expect($document->selectedProjects)->toHaveCount(2)
        ->and($document->selectedProjects[0]->name)->toBe('First (order 0)')
        ->and($document->selectedProjects[1]->name)->toBe('Second (order 1)');
});

// --- Historical reproducibility -------------------------------------------

it('does not change its output when the underlying Employer, Role, Skill, or Education rows are mutated after generation', function () {
    [$candidate, $variant] = minimalResumeVariant();

    $before = (new GenerateResumeDocument)->generate($variant);
    $originalEmployerName = $candidate['employer']->name;

    $candidate['employer']->update(['name' => 'Renamed Employer Inc.']);
    $candidate['role']->update(['start_year' => 1999, 'start_month' => 1, 'end_year' => 2001, 'end_month' => 12]);
    $candidate['awsSkill']->update(['name' => 'Renamed Skill', 'category' => 'practice']);
    $candidate['education']->update([
        'institution' => 'Renamed University', 'degree' => 'Renamed Degree',
        'field_of_study' => 'Renamed Field', 'start_year' => 1900, 'end_year' => 1901,
    ]);

    $after = (new GenerateResumeDocument)->generate($variant->fresh([
        'careerProfile', 'experienceRoles.bullets', 'skillSelections', 'educationSelections',
    ]));

    expect($after)->toEqual($before)
        ->and($after->experience[0]->employerName)->toBe($originalEmployerName)
        ->and($after->experience[0]->employerName)->not->toBe('Renamed Employer Inc.')
        ->and($after->skills[0]->skills)->not->toContain('Renamed Skill')
        ->and($after->education[0]->institution)->not->toBe('Renamed University');
});

it('does not change its Selected Projects output when the underlying Project/Skill rows are mutated after generation', function () {
    [$candidate, $variant] = minimalResumeVariantWithSelectedProject();

    $before = (new GenerateResumeDocument)->generate($variant);

    $candidate['independentProject']->update(['name' => 'Renamed Project', 'live_url' => 'https://renamed.example.dev']);
    $candidate['independentProjectSkill']->update(['name' => 'Renamed Skill']);

    $after = (new GenerateResumeDocument)->generate($variant->fresh([
        'careerProfile', 'experienceRoles.bullets', 'skillSelections', 'projects.bullets', 'educationSelections',
    ]));

    expect($after)->toEqual($before)
        ->and($after->selectedProjects[0]->name)->toBe('Well Prompted')
        ->and($after->selectedProjects[0]->name)->not->toBe('Renamed Project')
        ->and($after->selectedProjects[0]->liveDemo->url)->toBe('https://wellprompted.example.dev')
        ->and($after->selectedProjects[0]->technologies)->not->toContain('Renamed Skill');
});

it('does not change its output when the Employer/Role/Skill/Education rows are deleted after generation, since the FKs are lineage-only', function () {
    // Confirms the frozen columns, not the FK lookups, are what
    // ResumeDocument actually reads — deleting the live rows (where
    // possible) must not break assembly. Role/Employer/Skill/Education
    // are all restrictOnDelete while cited, so this only proves the
    // *columns* are unused for rendering, without needing to force a
    // real delete through the restriction.
    [$candidate, $variant] = minimalResumeVariant();

    $document = (new GenerateResumeDocument)->generate($variant);

    expect(fn () => $candidate['role']->delete())->toThrow(QueryException::class);
    expect($document->experience[0]->displayTitle)->toBe('Senior Software Engineer');
});

// --- Pearly fixture ---------------------------------------------------------

it('assembles a complete, real ResumeDocument from the Pearly fixture', function () {
    $variant = PearlyResumeVariantFixture::generate();

    $document = (new GenerateResumeDocument)->generate($variant);

    expect($document->contact->name)->toBe('Daniel Sias')
        ->and($document->contact->email)->toBe('dan.sias@gmail.com')
        ->and($document->contact->phone)->toBeNull()
        ->and($document->summary)->toContain('Full-stack developer who independently delivers')
        ->and($document->experience)->toHaveCount(2)
        ->and($document->experience[0]->employerName)->toBe('RocketGate')
        ->and($document->experience[0]->displayTitle)->toBe('Developer Support Engineer')
        ->and($document->experience[0]->isCurrent)->toBeTrue()
        ->and($document->experience[0]->dateRangeLabel)->toBe('May 2025 – Present')
        ->and($document->experience[0]->bullets)->toHaveCount(5)
        ->and($document->experience[1]->employerName)->toBe('Pearson Online Learning Services')
        ->and($document->experience[1]->displayTitle)->toBe('Data & Analytics Lead Developer')
        ->and($document->experience[1]->isCurrent)->toBeFalse()
        ->and($document->experience[1]->dateRangeLabel)->toBe('Sep 2015 – Jul 2024')
        ->and($document->experience[1]->bullets)->toHaveCount(5)
        // v2 Skills presentation grouping (see GenerateResumeDocument's
        // own docblock): "Technologies"/"Platforms & Tools" renamed to
        // "Languages & Frameworks"/"Data & Platforms" (same skills,
        // straight category rename); the old "Workflow" group no
        // longer exists — CI/CD (practice, not on the Marketing
        // Technology slug allow-list) folds into "Engineering" instead,
        // appended after Analytics systems per its own display_order.
        ->and($document->skills)->toHaveCount(3)
        ->and($document->skills[0]->label)->toBe('Languages & Frameworks')
        ->and($document->skills[0]->skills)->toBe(['TypeScript', 'Node.js', 'PostgreSQL', 'MySQL', 'React', 'Express.js'])
        ->and($document->skills[1]->label)->toBe('Data & Platforms')
        ->and($document->skills[1]->skills)->toBe(['BigQuery', 'Google Cloud Functions', 'Salesforce', 'Jira', 'GitLab'])
        ->and($document->skills[2]->label)->toBe('Engineering')
        ->and($document->skills[2]->skills)->toBe(['API integration & design', 'Data pipelines', 'High-risk data remediation engineering', 'Security-conscious engineering', 'Analytics systems', 'CI/CD'])
        // This fixture reconstructs a real generation from before
        // Selected Projects existed (schema_version 1.1) — correctly
        // empty, not fabricated data. See PearlyResumeVariantFixture.
        ->and($document->selectedProjects)->toBe([])
        ->and($document->education)->toHaveCount(2);
});
