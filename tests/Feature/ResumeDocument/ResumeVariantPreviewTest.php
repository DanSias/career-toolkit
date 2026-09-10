<?php

use App\Models\ResumeVariant;
use App\Models\Skill;
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
        'phone' => '(407) 272-1720',
        'location' => 'Orlando, FL',
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

it('renders the portfolio link as a real anchor with the canonical URL as href and the human-readable domain as visible text', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->toContain('<a href="https://example.dev">example.dev</a>');
});

it('omits GitHub from the default rendered header even though the candidate has a github_url', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->not->toContain('github.com/example')
        ->and($html)->not->toContain('<a href="https://github.com/example"');
});

it('omits location from the default rendered header', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    expect($response->getContent())->not->toContain('Orlando, FL');
});

it('renders nothing for portfolio when it is null', function () {
    [$candidate, $variant] = previewCandidateVariant();
    $candidate['profile']->update(['portfolio_url' => null, 'github_url' => null]);

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk();
    expect($response->getContent())->not->toContain('<a href');
});

it('renders the default contact line in the order email, then phone, then portfolio', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $response->assertOk();
    $html = $response->getContent();

    $emailPos = strpos($html, 'candidate@example.com');
    $phonePos = strpos($html, '(407) 272-1720');
    $portfolioPos = strpos($html, 'example.dev');

    expect($emailPos)->not->toBeFalse()
        ->and($phonePos)->not->toBeFalse()
        ->and($portfolioPos)->not->toBeFalse()
        ->and($emailPos)->toBeLessThan($phonePos)
        ->and($phonePos)->toBeLessThan($portfolioPos);
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

it('renders sections in v1 display order: Summary, then Skills, then Experience, then Education', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $response->assertOk();
    $html = $response->getContent();

    $summaryPos = strpos($html, 'A targeted preview summary.');
    $skillsPos = strpos($html, '>Technical Skills<');
    $experiencePos = strpos($html, '>Professional Experience<');
    $educationPos = strpos($html, '>Education<');

    expect($summaryPos)->not->toBeFalse()
        ->and($skillsPos)->not->toBeFalse()
        ->and($experiencePos)->not->toBeFalse()
        ->and($educationPos)->not->toBeFalse()
        ->and($summaryPos)->toBeLessThan($skillsPos)
        ->and($skillsPos)->toBeLessThan($experiencePos)
        ->and($experiencePos)->toBeLessThan($educationPos);
});

it('renders no visible Summary section heading, while the summary text itself still renders', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $response->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('A targeted preview summary.')
        ->and($html)->not->toContain('>Summary<');
});

it('renders conventional, ATS-recognizable section heading text for Skills and Experience', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk()
        ->assertSee('Technical Skills')
        ->assertSee('Professional Experience')
        ->assertSee('Education');
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

it('renders sections in order: Skills, Experience, Selected Projects, Education, when a Project is selected', function () {
    [, $variant] = previewCandidateVariantWithSelectedProject();

    $response = $this->get(route('resume-variants.preview', $variant));
    $response->assertOk();
    $html = $response->getContent();

    $skillsPos = strpos($html, '>Technical Skills<');
    $experiencePos = strpos($html, '>Professional Experience<');
    $selectedProjectsPos = strpos($html, '>Selected Projects<');
    $educationPos = strpos($html, '>Education<');

    expect($skillsPos)->not->toBeFalse()
        ->and($experiencePos)->not->toBeFalse()
        ->and($selectedProjectsPos)->not->toBeFalse()
        ->and($educationPos)->not->toBeFalse()
        ->and($skillsPos)->toBeLessThan($experiencePos)
        ->and($experiencePos)->toBeLessThan($selectedProjectsPos)
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

// --- Employment header structure --------------------------------------------

it('renders the Role title, company, and dates as three DOM elements in that logical order — title, company, date — regardless of visual CSS layout', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $html = $response->getContent();

    expect($html)->toContain('<p class="role-title">Senior Software Engineer</p>')
        ->and($html)->toContain('<p class="role-employer">'.e($candidate['employer']->name).'</p>')
        ->and($html)->toContain('<p class="role-dates">');

    $titlePos = strpos($html, 'class="role-title"');
    $employerPos = strpos($html, 'class="role-employer"');
    $datesPos = strpos($html, 'class="role-dates"');

    expect($titlePos)->not->toBeFalse()
        ->and($employerPos)->not->toBeFalse()
        ->and($datesPos)->not->toBeFalse()
        ->and($titlePos)->toBeLessThan($employerPos)
        ->and($employerPos)->toBeLessThan($datesPos);
});

it('renders Role title/company/dates as plain block-level <p> elements, so a CSS-off view still stacks them as three lines in DOM order', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $html = $response->getContent();

    expect($html)->toMatch('/<p class="role-title">[^<]*<\/p>\s*<p class="role-employer">[^<]*<\/p>\s*<p class="role-dates">[^<]*<\/p>/');
});

it('renders the Role date range with abbreviated three-letter months', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));

    $response->assertOk()->assertSee('Jan 2021 – Present');
});

it('renders the Education degree on its own primary line, and institution/dates on a separate secondary line', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $html = $response->getContent();

    expect($html)->toContain('<p class="education-degree-line">')
        ->and($html)->toContain('<p class="education-meta-line">')
        ->and($html)->toContain('<span class="education-institution">'.e($candidate['education']->institution).'</span>');

    $degreeLinePos = strpos($html, 'education-degree-line');
    $metaLinePos = strpos($html, 'education-meta-line');

    expect($degreeLinePos)->not->toBeFalse()
        ->and($metaLinePos)->not->toBeFalse()
        ->and($degreeLinePos)->toBeLessThan($metaLinePos);
});

// --- Skills DOM/text ordering ------------------------------------------------

it('renders each Skills group label on its own line, immediately above a line of its skills', function () {
    [$candidate, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $html = $response->getContent();

    expect($html)->toContain('<p class="skill-group-items">'.$candidate['awsSkill']->name.'</p>');

    // The label line and items line for the same group appear back to
    // back — no intervening markup — proving the two-line structure
    // rather than the old one-line "Label: skills" paragraph.
    expect($html)->toMatch('/<p class="skill-group-label">[^<]+<\/p>\s*<p class="skill-group-items">'.preg_quote($candidate['awsSkill']->name, '/').'<\/p>/');
});

it('joins multiple skills within a group with a middle dot, not a comma', function () {
    [$candidate, $variant] = previewCandidateVariant();
    $terraform = Skill::factory()->create([
        'career_profile_id' => $candidate['profile']->id, 'name' => 'Terraform', 'category' => $candidate['awsSkill']->category->value,
    ]);
    $variant->skillSelections()->create([
        'skill_id' => $terraform->id,
        'name' => 'Terraform',
        'category' => $terraform->category->value,
        'display_order' => 2,
    ]);

    $response = $this->get(route('resume-variants.preview', $variant->fresh()));

    $response->assertOk()->assertSee('AWS · Terraform', false);
});

// --- Print pagination CSS rules --------------------------------------------
//
// Deterministic assertions on the *rules themselves* (text-matched
// against the rendered <style> block), never on rendered pixel
// positions or page counts — those are verified separately against
// the real Chromium print pipeline, not in this suite. See
// docs/domain-model.md "ResumeVariant" -> "Selected Projects" and the
// print.blade.php docblock for the pagination model these encode.

/**
 * Extracts exactly one CSS rule's body by selector — `\.role\s*\{`
 * requires "role" to be followed immediately by `{` (optional
 * whitespace between), so this can't accidentally match a longer
 * selector like `.role-header` or `.role-title`. CSS comments are
 * stripped first so an explanatory `/* ... *\/` comment (e.g. one
 * documenting which property is deliberately absent) can never be
 * mistaken for the property itself.
 */
function cssRuleBody(string $css, string $selector): ?string
{
    $withoutComments = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    $pattern = '/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/';

    return preg_match($pattern, $withoutComments, $matches) === 1 ? $matches[1] : null;
}

it('no longer treats a whole Role as one unbreakable print unit', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $css = $response->getContent();

    $roleRule = cssRuleBody($css, '.role');

    expect($roleRule)->not->toBeNull()
        ->and($roleRule)->not->toContain('break-inside')
        ->and($roleRule)->not->toContain('page-break-inside');
});

it('keeps a Role heading glued to whatever follows it (its first bullet), and keeps the heading itself unsplit', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $css = $response->getContent();

    $headerRule = cssRuleBody($css, '.role-header');

    expect($headerRule)->not->toBeNull()
        ->and($headerRule)->toContain('break-after: avoid')
        ->and($headerRule)->toContain('page-break-after: avoid')
        ->and($headerRule)->toContain('break-inside: avoid');
});

it('treats every individual bullet (Experience and Selected Project) as an indivisible print unit', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $css = $response->getContent();

    $bulletRule = cssRuleBody($css, 'ul.bullets li');

    expect($bulletRule)->not->toBeNull()
        ->and($bulletRule)->toContain('break-inside: avoid')
        ->and($bulletRule)->toContain('page-break-inside: avoid');
});

it('never lets any section heading be stranded alone at the bottom of a page — a generic rule, not per-section', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $css = $response->getContent();

    $h2Rule = cssRuleBody($css, 'h2');

    expect($h2Rule)->not->toBeNull()
        ->and($h2Rule)->toContain('break-after: avoid')
        ->and($h2Rule)->toContain('page-break-after: avoid');
});

it('keeps small semantic units (Summary paragraph, a Skills category line, an Education entry, a Selected Project entry) print-indivisible', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $css = $response->getContent();

    foreach (['.summary-text', '.skill-group', '.education-entry', '.selected-project'] as $selector) {
        $rule = cssRuleBody($css, $selector);

        expect($rule)->not->toBeNull()
            ->and($rule)->toContain('break-inside: avoid');
    }
});

it('never groups all Education entries into one unbreakable block — only each individual entry', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $css = $response->getContent();

    // No selector targets the whole .education section as an atomic
    // print unit — only .education-entry (already asserted above).
    $educationSectionRule = cssRuleBody($css, '.education');

    expect($educationSectionRule)->toBeNull();
});

it('never uses a table or absolute positioning anywhere, including for the Role title/date line layout', function () {
    [, $variant] = previewCandidateVariant();

    $response = $this->get(route('resume-variants.preview', $variant));
    $html = $response->getContent();

    expect($html)->not->toContain('<table')
        ->and($html)->not->toContain('position: absolute')
        ->and($html)->not->toContain('position:absolute');
});
