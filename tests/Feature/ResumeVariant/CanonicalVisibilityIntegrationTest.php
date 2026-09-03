<?php

use App\Models\CareerFact;
use App\Support\ResumeVariant\ResumeEligibility;
use Illuminate\Support\Facades\Artisan;

/**
 * Verifies the resume-output eligibility rule against the REAL,
 * currently-imported canonical dataset — not a fixture — specifically
 * confirming the visibility-curation review (commit 578caab) is
 * correctly reflected: the seven reclassified Transaction Remediation
 * facts are excluded, and the already-reviewed "32,000+" conservative
 * headline remains available. See docs/domain-model.md "ResumeVariant".
 */
beforeEach(function () {
    Artisan::call('career:import');
});

it('keeps the 32,000+ corrected-record headline eligible for a targeted resume', function () {
    $fact = CareerFact::where('key', 'rocketgate-transaction-remediation-total-corrected')->firstOrFail();

    expect($fact->visibility->value)->toBe('restricted')
        ->and(ResumeEligibility::isEligible($fact))->toBeTrue();
});

it('excludes all seven newly-Private granular Transaction Remediation facts', function () {
    $privateKeys = [
        'rocketgate-transaction-remediation-phase1-transactions-corrected',
        'rocketgate-transaction-remediation-phase2-initial-scope-expansion',
        'rocketgate-transaction-remediation-phase2-transactions-corrected',
        'rocketgate-transaction-remediation-phase2-missing-values-backfilled',
        'rocketgate-transaction-remediation-phase2-conflicts-resolved',
        'rocketgate-transaction-remediation-final-audit-population',
        'rocketgate-transaction-remediation-final-audit-desired-state',
    ];

    foreach ($privateKeys as $key) {
        $fact = CareerFact::where('key', $key)->firstOrFail();

        expect($fact->visibility->value)->toBe('private')
            ->and(ResumeEligibility::isEligible($fact))->toBeFalse();
    }
});

it('keeps the eleven technique/process Restricted facts eligible', function () {
    $keepRestrictedKeys = [
        'rocketgate-ai-assisted-knowledge-management',
        'rocketgate-notebooklm-knowledge-base',
        'rocketgate-workflow-intelligence-independently-implemented',
        'rocketgate-verbatim-support-reply-reuse',
        'rocketgate-transaction-remediation-what-it-is',
        'rocketgate-transaction-remediation-deterministic-donor-lineage',
        'rocketgate-transaction-remediation-plan-and-dry-run',
        'rocketgate-transaction-remediation-safe-live-execution',
        'rocketgate-transaction-remediation-resumable-recovery',
        'rocketgate-transaction-remediation-post-write-verification',
        'rocketgate-transaction-remediation-audit-trails-completeness-audits',
    ];

    foreach ($keepRestrictedKeys as $key) {
        $fact = CareerFact::where('key', $key)->firstOrFail();

        expect($fact->visibility->value)->toBe('restricted')
            ->and(ResumeEligibility::isEligible($fact))->toBeTrue();
    }
});

it('matches the exact expected visibility distribution: 32 public / 12 restricted / 7 private', function () {
    $counts = CareerFact::query()->selectRaw('visibility, count(*) as c')->groupBy('visibility')->pluck('c', 'visibility');

    expect($counts['public'])->toBe(32)
        ->and($counts['restricted'])->toBe(12)
        ->and($counts['private'])->toBe(7);
});
