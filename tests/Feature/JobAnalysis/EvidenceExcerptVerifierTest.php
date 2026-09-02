<?php

use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Support\JobAnalysis\EvidenceExcerptVerifier;
use Tests\Support\JobAnalysisFixtures;

function evidenceVerifier(): EvidenceExcerptVerifier
{
    return new EvidenceExcerptVerifier;
}

it('accepts a valid single evidence excerpt', function () {
    $payload = JobAnalysisFixtures::validPayload();

    evidenceVerifier()->verify($payload, JobAnalysisFixtures::DESCRIPTION);

    expect(true)->toBeTrue(); // verify() returning at all, without throwing, is the pass condition.
});

it('accepts valid repeated evidence on the same finding', function () {
    $payload = JobAnalysisFixtures::validPayload();

    // The "travel" finding in the shared fixture already carries two
    // separate, independently-verifiable evidence rows.
    expect($payload['findings'][1]['evidence'])->toHaveCount(2);

    evidenceVerifier()->verify($payload, JobAnalysisFixtures::DESCRIPTION);

    expect(true)->toBeTrue();
});

it('accepts an excerpt that only matches after whitespace-run normalization', function () {
    $payload = JobAnalysisFixtures::validPayload();
    // The real description has a single space between these words; the
    // "returned" excerpt reproduces it with a line break and extra
    // spaces, as an LLM reformatting the text might.
    $payload['findings'][0]['evidence'][0]['excerpt'] =
        "Minimum 5 years\n   of backend engineering  experience required.";

    evidenceVerifier()->verify($payload, JobAnalysisFixtures::DESCRIPTION);

    expect(true)->toBeTrue();
});

it('rejects an excerpt that does not appear in the source description at all', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence'][0]['excerpt'] = 'This sentence was never in the posting.';

    evidenceVerifier()->verify($payload, JobAnalysisFixtures::DESCRIPTION);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects a paraphrased excerpt even when it is semantically equivalent', function () {
    $payload = JobAnalysisFixtures::validPayload();
    // Same meaning as the real sentence, but not a verbatim substring —
    // must NOT be accepted, per the strict v1 policy.
    $payload['findings'][0]['evidence'][0]['excerpt'] = 'At least five years of backend experience is needed.';

    evidenceVerifier()->verify($payload, JobAnalysisFixtures::DESCRIPTION);
})->throws(InvalidJobAnalysisResponseException::class);

it('rejects the ENTIRE analysis when only one excerpt among many valid ones is unverifiable', function () {
    $payload = JobAnalysisFixtures::validPayload();
    // Corrupt only the second evidence entry of the second finding —
    // every other finding/evidence entry in this payload is valid.
    $payload['findings'][1]['evidence'][1]['excerpt'] = 'Fabricated quote that does not exist in the posting.';

    evidenceVerifier()->verify($payload, JobAnalysisFixtures::DESCRIPTION);
})->throws(InvalidJobAnalysisResponseException::class);

it('does not fold case when matching excerpts', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence'][0]['excerpt'] = 'minimum 5 years of backend engineering experience required.';

    evidenceVerifier()->verify($payload, JobAnalysisFixtures::DESCRIPTION);
})->throws(InvalidJobAnalysisResponseException::class);

it('carries structured diagnostic context identifying exactly which excerpt failed', function () {
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence'][0]['excerpt'] = 'Fabricated — never appears in the posting.';

    try {
        evidenceVerifier()->verify($payload, JobAnalysisFixtures::DESCRIPTION);
        $this->fail('Expected InvalidJobAnalysisResponseException to be thrown.');
    } catch (InvalidJobAnalysisResponseException $e) {
        expect($e->context)->not->toBeNull()
            ->and($e->context['finding_index'])->toBe(0)
            ->and($e->context['evidence_index'])->toBe(0)
            ->and($e->context['excerpt'])->toBe('Fabricated — never appears in the posting.')
            ->and($e->context['finding']['statement'])->toBe($payload['findings'][0]['statement'])
            ->and($e->context['finding']['category'])->toBe($payload['findings'][0]['category']);
    }
});
