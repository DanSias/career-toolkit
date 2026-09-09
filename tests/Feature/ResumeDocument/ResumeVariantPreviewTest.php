<?php

use App\Models\ResumeVariant;
use Illuminate\Support\Facades\Http;
use Tests\Support\ResumeVariantFixtures;

/**
 * Proves the deterministic, non-Inertia preview route
 * (GET /resume-variants/{resumeVariant}/preview) renders the exact
 * ResumeDocument GenerateResumeDocument builds — never live Eloquent
 * data reached into directly from the Blade template, and never a
 * provider call.
 */
function previewCandidateVariant(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    $candidate['profile']->update([
        'email' => 'candidate@example.com',
        'phone' => null,
        'location' => null,
        'portfolio_url' => 'https://example.dev',
        'github_url' => 'https://github.com/example',
    ]);

    $variant = ResumeVariant::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'job_match_id' => $match->id,
        'summary' => 'A targeted preview summary.',
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

function previewCandidateVariantWithSelectedProject(): array
{
    [$candidate, $variant] = previewCandidateVariant();

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

it('returns HTML for a valid ResumeVariant', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/html');
});

it('renders the ResumeDocument summary, employer, title, bullets, skills, and education', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk()
        ->assertSee('A targeted preview summary.')
        ->assertSee($candidate['employer']->name)
        ->assertSee('Senior Software Engineer')
        ->assertSee('Built cloud-hosted deployment workflows on AWS.')
        ->assertSee($candidate['awsSkill']->name)
        ->assertSee($candidate['education']->institution);
});

it('omits null contact fields rather than rendering an empty placeholder', function () {
    [$candidate, $variant] = previewCandidateVariant();
    $candidate['profile']->update(['phone' => null, 'location' => null]);

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->not->toContain('null')
        ->and($html)->not->toContain('Phone:')
        ->and($html)->not->toContain('Location:');
});

it('renders portfolio and GitHub links as real anchors with the canonical URL as href and the link label as visible text', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->toContain('<a href="https://example.dev">example.dev</a>')
        ->and($html)->toContain('<a href="https://github.com/example">github.com/example</a>');
});

it('renders nothing for portfolio/GitHub when both are null', function () {
    [$candidate, $variant] = previewCandidateVariant();
    $candidate['profile']->update(['portfolio_url' => null, 'github_url' => null]);

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    expect($response->getContent())->not->toContain('<a href');
});

it('renders the frozen ResumeDocument content, not live domain data, when the underlying Employer/Skill are mutated after generation', function () {
    [$candidate, $variant] = previewCandidateVariant();
    $originalEmployerName = $candidate['employer']->name;

    $candidate['employer']->update(['name' => 'Renamed Employer Inc.']);
    $candidate['awsSkill']->update(['name' => 'Renamed Skill']);

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->not->toContain('Renamed Employer Inc.')
        ->and($html)->not->toContain('Renamed Skill')
        ->and($html)->toContain($originalEmployerName);
});

it('renders sections in ResumeDocument order: Summary, then Experience, then Skills, then Education', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $response->assertOk();
    $html = $response->getContent();

    $summaryPos = strpos($html, '>Summary<');
    $experiencePos = strpos($html, '>Experience<');
    $skillsPos = strpos($html, '>Skills<');
    $educationPos = strpos($html, '>Education<');

    expect($summaryPos)->not->toBeFalse()
        ->and($experiencePos)->not->toBeFalse()
        ->and($skillsPos)->not->toBeFalse()
        ->and($educationPos)->not->toBeFalse()
        ->and($summaryPos)->toBeLessThan($experiencePos)
        ->and($experiencePos)->toBeLessThan($skillsPos)
        ->and($skillsPos)->toBeLessThan($educationPos);
});

it('omits the entire Selected Projects section — no heading at all — when no Project was selected', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    expect($response->getContent())->not->toContain('Selected Projects');
});

it('renders the Selected Projects name, technologies, bullet, and both links when present', function () {
    [, $variant] = previewCandidateVariantWithSelectedProject();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk()
        ->assertSee('Selected Projects')
        ->assertSee('Well Prompted')
        ->assertSee('Prisma, Supabase', false)
        ->assertSee('Built a structured prompt library for reusable AI-assisted development workflows.');

    $html = $response->getContent();
    expect($html)->toContain('<a href="https://wellprompted.example.dev">Live Demo</a>')
        ->and($html)->toContain('<a href="https://github.com/example/well-prompted">Repository</a>');
});

it('renders sections in order: Summary, Experience, Skills, Selected Projects, Education, when a Project is selected', function () {
    [, $variant] = previewCandidateVariantWithSelectedProject();

    $response = $this->get(route('resume-variants.preview', $variant));
    $response->assertOk();
    $html = $response->getContent();

    $skillsPos = strpos($html, '>Skills<');
    $selectedProjectsPos = strpos($html, '>Selected Projects<');
    $educationPos = strpos($html, '>Education<');

    expect($skillsPos)->not->toBeFalse()
        ->and($selectedProjectsPos)->not->toBeFalse()
        ->and($educationPos)->not->toBeFalse()
        ->and($skillsPos)->toBeLessThan($selectedProjectsPos)
        ->and($selectedProjectsPos)->toBeLessThan($educationPos);
});

it('renders the frozen Selected Projects content, not live Project/Skill data, when they are mutated after generation', function () {
    [$candidate, $variant] = previewCandidateVariantWithSelectedProject();

    $candidate['independentProject']->update(['name' => 'Renamed Project']);
    $candidate['independentProjectSkill']->update(['name' => 'Renamed Skill']);

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->not->toContain('Renamed Project')
        ->and($html)->not->toContain('Renamed Skill')
        ->and($html)->toContain('Well Prompted');
});

it('makes no outbound HTTP/provider calls while rendering the preview', function () {
    Http::preventStrayRequests();
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    Http::assertNothingSent();
});
