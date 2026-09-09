<?php

use App\Exceptions\InvalidResumeVariantResponseException;
use App\Support\ResumeVariant\ResumeSelectionResponseValidator;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;

/**
 * Guards the diagnostic-capture fix made after two failed live Pearly
 * Selection attempts whose raw structured responses were unrecoverable
 * — neither validator populated the pre-existing, previously-unused
 * `InvalidResumeVariantResponseException::$context` field. This does
 * not change what causes validation to fail, only what a caller can
 * inspect afterward — the live-eval harness uses this to persist a
 * local, gitignored capture of a rejected response. See
 * docs/resume-variant-generation.md "Live evaluation".
 */
it('carries the raw structured content in $context when Resume Selection validation fails', function () {
    $invalid = ['summary_evidence' => [], 'skills' => [], 'education_selection' => [], 'experience' => [], 'target_term_usages' => 'not-an-array'];

    try {
        (new ResumeSelectionResponseValidator)->validate($invalid, [], [], [], [], [], [], [], [], [], []);
        $this->fail('Expected InvalidResumeVariantResponseException to be thrown.');
    } catch (InvalidResumeVariantResponseException $e) {
        expect($e->context)->toBe($invalid);
    }
});

it('carries the raw structured content in $context when Resume Wording validation fails', function () {
    $invalid = ['summary' => 'Some summary.', 'experience' => 'not-an-array'];

    try {
        (new ResumeWordingResponseValidator)->validate($invalid, [], [], [], [], [], []);
        $this->fail('Expected InvalidResumeVariantResponseException to be thrown.');
    } catch (InvalidResumeVariantResponseException $e) {
        expect($e->context)->toBe($invalid);
    }
});
