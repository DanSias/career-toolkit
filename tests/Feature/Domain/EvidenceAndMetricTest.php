<?php

use App\Enums\EvidenceSource;
use App\Enums\MetricComparator;
use App\Models\CareerFact;
use App\Models\Evidence;
use App\Models\Metric;

it('lets a career fact carry multiple evidence records from different sources', function () {
    $fact = CareerFact::factory()->create();

    Evidence::factory()->resume()->for($fact)->create();
    Evidence::factory()->portfolio()->for($fact)->create();
    Evidence::factory()->repository()->for($fact)->create();

    expect($fact->evidence)->toHaveCount(3)
        ->and($fact->evidence->pluck('source')->map->value->sort()->values()->all())
        ->toBe(['portfolio', 'repository', 'resume']);
});

it('represents heterogeneous provenance shapes without a source-specific table', function () {
    $fact = CareerFact::factory()->create();

    $resumeEvidence = Evidence::factory()->for($fact)->create([
        'source' => EvidenceSource::Resume,
        'document' => 'sources/resume/Daniel_Sias_Resume.pdf',
        'section' => 'Professional Experience',
        'locator' => 'RocketGate bullet 2',
    ]);

    $portfolioEvidence = Evidence::factory()->for($fact)->create([
        'source' => EvidenceSource::Portfolio,
        'path' => '../danielsias-dev/src/app/projects/workflow-intelligence/page.tsx',
        'locator' => 'Responsibilities',
    ]);

    $repositoryEvidence = Evidence::factory()->for($fact)->create([
        'source' => EvidenceSource::Repository,
        'path' => '../rg-transaction-reconstruction/README.md',
        'locator' => 'donor-selection rules',
    ]);

    $userConfirmedEvidence = Evidence::factory()->for($fact)->create([
        'source' => EvidenceSource::UserConfirmed,
        'confirmed_at' => now(),
        'note' => 'confirmed directly by user',
    ]);

    expect($resumeEvidence->document)->toBe('sources/resume/Daniel_Sias_Resume.pdf')
        ->and($resumeEvidence->section)->toBe('Professional Experience')
        ->and($portfolioEvidence->path)->toContain('workflow-intelligence')
        ->and($repositoryEvidence->path)->toContain('rg-transaction-reconstruction')
        ->and($userConfirmedEvidence->confirmed_at)->not->toBeNull()
        ->and($userConfirmedEvidence->note)->toBe('confirmed directly by user');
});

it('preserves each evidence record\'s own literal source wording', function () {
    $fact = CareerFact::factory()->create(['statement' => 'Reduced reporting time by 85%.']);

    $resumeEvidence = Evidence::factory()->resume()->for($fact)->create([
        'quoted_text' => 'reducing report generation time by 85%',
    ]);

    $portfolioEvidence = Evidence::factory()->portfolio()->for($fact)->create([
        'quoted_text' => 'Cut reporting time by 85%',
    ]);

    // Differing source wording is preserved per-evidence, not collapsed
    // into (or lost behind) the fact's single canonical statement.
    expect($resumeEvidence->quoted_text)->not->toBe($portfolioEvidence->quoted_text)
        ->and($fact->statement)->toBe('Reduced reporting time by 85%.');
});

it('lets a career fact optionally have a structured metric', function () {
    $factWithMetric = CareerFact::factory()->create();
    $factWithoutMetric = CareerFact::factory()->create();

    Metric::factory()->for($factWithMetric)->create([
        'value' => 85,
        'unit' => 'percent_reduction',
        'comparator' => MetricComparator::Exact,
    ]);

    expect($factWithMetric->fresh()->metric)->not->toBeNull()
        ->and($factWithMetric->fresh()->metric->value)->toEqual('85.00')
        ->and($factWithoutMetric->fresh()->metric)->toBeNull();
});

it('carries a misuse guardrail on a metric without changing the fact statement', function () {
    $fact = CareerFact::factory()->create([
        'statement' => 'Gave marketing teams visibility into $25M+ in tracked spend.',
    ]);

    $metric = Metric::factory()->for($fact)->create([
        'value' => 25000000,
        'unit' => 'usd',
        'comparator' => MetricComparator::AtLeast,
        'scope_note' => 'Scope of spend the analytics platform tracked, not a budget owned or optimized.',
        'guardrail' => 'Do not restate as budget ownership or revenue generated.',
    ]);

    expect($metric->guardrail)->toBe('Do not restate as budget ownership or revenue generated.')
        ->and($metric->comparator)->toBe(MetricComparator::AtLeast);
});
