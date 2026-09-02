<?php

use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// tests/Llm holds the opt-in, real-provider five-posting evaluation —
// deliberately outside phpunit.xml's default testsuites, so it never
// runs as part of `php artisan test` / the default Feature+Unit suite.
// Run it explicitly: `vendor/bin/pest tests/Llm`. See
// docs/job-analysis-generation.md.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Llm');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Shared by every test under tests/Llm — moved here (rather than living
 * in JobAnalysisLiveCorpusTest.php, where they originated) because
 * Pest.php is always loaded regardless of which specific test file is
 * targeted on the command line, while a sibling test file is only
 * included when the whole directory (or that file itself) is targeted.
 * Running `vendor/bin/pest tests/Llm/JobMatchLiveCorpusTest.php` alone
 * (a supported, documented invocation — see that file's own docblock)
 * previously failed with "Call to undefined function
 * jobAnalysisCorpusPosting()" for exactly this reason: harness/discovery
 * bug, not model behavior. sources/jobs/job-analysis-design-set.md
 * remains the single source of truth for the five postings — parsed
 * fresh from that file below, never duplicated into a fixture.
 */

/**
 * @return array<int, array{company: string, title: string, description: string}>
 */
function jobAnalysisCorpusPostings(): array
{
    $path = base_path('sources/jobs/job-analysis-design-set.md');
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Could not read corpus file at [{$path}].");
    }

    $sections = preg_split('/^## /m', $contents);
    array_shift($sections); // the file's leading title/intro, before the first posting

    return array_map(function (string $section): array {
        [$headingLine, $rest] = explode("\n", $section, 2);
        [$company, $title] = array_map('trim', explode('—', $headingLine, 2));

        // Everything from the heading down to the next top-level "---"
        // divider (or end of file, for the last posting) is that
        // posting's captured source material — Source/Location/
        // Compensation header lines included, exactly as a human
        // copy-pasting a real listing would capture it. Only the
        // optional "### Original Job Description" subheading (present
        // on some but not all entries) is dropped, since it's this
        // file's own organizing label, not posting content.
        $body = preg_replace('/\n---\s*$/', '', rtrim($rest)) ?? rtrim($rest);
        $body = preg_replace('/^###\s*Original Job Description\s*$/mi', '', $body) ?? $body;

        return ['company' => $company, 'title' => $title, 'description' => trim($body)];
    }, $sections);
}

function jobAnalysisCorpusPosting(string $companyPrefix): JobPosting
{
    $postings = jobAnalysisCorpusPostings();
    $match = collect($postings)->firstWhere(fn (array $p) => str_starts_with($p['company'], $companyPrefix));

    if ($match === null) {
        throw new RuntimeException("No corpus posting found for company starting with [{$companyPrefix}].");
    }

    // The corpus captures each posting as one verbatim blob — location
    // isn't parsed out as its own structured field, it's already
    // embedded wherever the source itself states it (or doesn't) inside
    // `description`. Explicitly nulling it here is required: without
    // it, JobPostingFactory's default (`fake()->city()`) silently
    // injects a random fictitious city into the prompt's separate
    // `Location:` line — the model then faithfully reports that
    // fabricated value back as evidence, which correctly (but
    // misleadingly) fails verification against `description`, since it
    // never appears there. See docs/job-analysis-generation.md.
    return JobPosting::factory()->create([...$match, 'location' => null]);
}
