<?php

namespace App\Support\ResumeDocument;

use App\Models\ResumeVariant;

/**
 * A deterministic, filesystem/download-safe PDF filename for one
 * ResumeVariant — built entirely from data already on the resume
 * pipeline (the owning CareerProfile's name and the target
 * JobPosting's company via ResumeVariant -> JobMatch -> JobAnalysis ->
 * JobPosting), never a random/opaque id, so two exports of the same
 * variant always produce the same filename.
 */
final class ResumePdfFilename
{
    public static function build(ResumeVariant $variant): string
    {
        $variant->loadMissing(['careerProfile', 'jobMatch.jobAnalysis.jobPosting']);

        $candidateName = $variant->careerProfile->name;
        $company = $variant->jobMatch->jobAnalysis->jobPosting->company;

        return self::sanitize("{$candidateName} Resume {$company}").'.pdf';
    }

    /**
     * Letters, digits, and single hyphens only — collapses any run of
     * other characters (spaces, punctuation) into one hyphen, and
     * trims leading/trailing hyphens, so the result is safe as both a
     * filesystem path segment and a Content-Disposition filename with
     * no quoting concerns.
     */
    private static function sanitize(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';

        return trim($slug, '-');
    }
}
