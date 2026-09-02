<?php

namespace Tests\Support;

/**
 * A shared source-of-truth job posting description plus a matching
 * valid structured-response payload, so tests exercising the
 * validator/evidence-verifier/orchestrator don't each hand-roll a
 * slightly different fixture. Every excerpt below is a real substring
 * of DESCRIPTION — deliberately, so "valid" fixtures actually pass
 * evidence verification.
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
                    'evidence' => [
                        [
                            'excerpt' => 'Minimum 5 years of backend engineering experience required.',
                            'source_section' => 'Requirements',
                            'source_locator' => null,
                        ],
                    ],
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
                    'evidence' => [
                        [
                            'excerpt' => 'Travel up to 25% required for client visits.',
                            'source_section' => 'Requirements',
                            'source_locator' => null,
                        ],
                        [
                            'excerpt' => 'Travel is expected periodically to support on-site engagements.',
                            'source_section' => 'Requirements',
                            'source_locator' => null,
                        ],
                    ],
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
                    'evidence' => [
                        [
                            'excerpt' => 'ERP experience is not required — we will train you on our systems.',
                            'source_section' => 'Requirements',
                            'source_locator' => null,
                        ],
                    ],
                ],
            ],
        ];
    }
}
