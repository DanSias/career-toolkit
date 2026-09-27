<?php

namespace Tests\Support;

use App\Support\JobAnalysis\SegmentJobPostingDescription;

/**
 * A shared source-of-truth job posting description plus a matching
 * valid structured-response payload, so tests exercising the
 * validator/orchestrator don't each hand-roll a slightly different
 * fixture. Every evidence_refs value below is a real segment id
 * produced by SegmentJobPostingDescription against DESCRIPTION —
 * confirmed by segmentIds() below, computed the same way
 * GenerateJobAnalysis does, never hand-guessed. See
 * docs/job-analysis-generation.md "Async Job Analysis" (V4 evidence
 * architecture).
 *
 * Segment ids for DESCRIPTION (confirmed via segmentIds(), stable
 * because DESCRIPTION never changes):
 *   S001 "We are hiring a Senior Backend Engineer to join our platform team."
 *   S002 "Requirements:"
 *   S003 "- Minimum 5 years of backend engineering experience required."
 *   S004 "- Travel up to 25% required for client visits."
 *   S005 "- Travel is expected periodically to support on-site engagements."
 *   S006 "- ERP experience is not required — we will train you on our systems."
 */
final class JobAnalysisFixtures
{
    public const DESCRIPTION = <<<'TEXT'
        We are hiring a Senior Backend Engineer to join our platform team.

        Requirements:
        - Minimum 5 years of backend engineering experience required.
        - Travel up to 25% required for client visits.
        - Travel is expected periodically to support on-site engagements.
        - ERP experience is not required — we will train you on our systems.
        TEXT;

    /**
     * The exact valid segment id set for DESCRIPTION — what
     * GenerateJobAnalysis would pass to JobAnalysisPromptV4::jsonSchema()
     * and JobAnalysisResponseValidator::validate() for this posting.
     *
     * @return array<int, string>
     */
    public static function segmentIds(): array
    {
        return array_keys((new SegmentJobPostingDescription)->segment(self::DESCRIPTION));
    }

    /**
     * @return array<string, mixed>
     */
    public static function validPayload(): array
    {
        return [
            'role_summary' => 'A senior backend engineering role on the platform team, with regular client-facing travel.',
            'overall_seniority' => 'senior',
            'seniority_rationale' => 'The posting explicitly titles the role Senior Backend Engineer and sets a five-year experience floor.',
            'findings' => [
                [
                    'category' => 'required_qualification',
                    'statement' => '5+ years of backend engineering experience',
                    'label' => 'backend_experience_floor',
                    'basis' => 'explicit',
                    'requirement_strength' => 'required',
                    'emphasis' => 'normal',
                    'maturity' => 'unspecified',
                    'years_experience_min' => 5.0,
                    'years_experience_max' => null,
                    'recency_requirement' => null,
                    'time_horizon' => null,
                    'notes' => null,
                    'evidence_refs' => ['S003'],
                ],
                [
                    'category' => 'travel',
                    'statement' => 'Travel up to 25% required for client visits',
                    'label' => null,
                    'basis' => 'explicit',
                    'requirement_strength' => 'required',
                    'emphasis' => 'high',
                    'maturity' => 'unspecified',
                    'years_experience_min' => null,
                    'years_experience_max' => null,
                    'recency_requirement' => null,
                    'time_horizon' => null,
                    'notes' => 'Stated twice in the posting.',
                    'evidence_refs' => ['S004', 'S005'],
                ],
                [
                    'category' => 'preferred_qualification',
                    'statement' => 'ERP experience is not required',
                    'label' => null,
                    'basis' => 'explicit',
                    'requirement_strength' => 'not_required',
                    'emphasis' => 'normal',
                    'maturity' => 'unspecified',
                    'years_experience_min' => null,
                    'years_experience_max' => null,
                    'recency_requirement' => null,
                    'time_horizon' => null,
                    'notes' => null,
                    'evidence_refs' => ['S006'],
                ],
            ],
        ];
    }
}
