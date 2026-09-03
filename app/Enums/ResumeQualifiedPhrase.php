<?php

namespace App\Enums;

/**
 * The fixed connector-phrase vocabulary a `Qualified`-posture target-
 * term usage may render through. Deterministic code appends the clause
 * ("... {phrase} {term}") to Stage 2's own free-generated prose — the
 * model never writes the qualifying fragment itself, only picks which
 * phrase fits.
 *
 * Deliberately excludes "directly transferable to": "directly" asserts
 * more semantic strength than adjacency evidence proves. Deliberately
 * excludes a bare "relevant to": too weak to earn a qualified-clause
 * slot — if the connection is that thin it belongs as `Capability`
 * posture with no clause at all, not a diluted qualified claim. See
 * docs/resume-variant-contract.md.
 *
 * `NotApplicable` is a schema-only sentinel (never a real rendering
 * value) so `relationship_phrase_key` can stay a required, non-nullable
 * enum field even on `Direct`/`Capability` usages, matching this
 * codebase's established avoidance of nullable+enum schema fields
 * (see JobMatchPromptV1's education_id sentinel).
 */
enum ResumeQualifiedPhrase: string
{
    case ApplicableTo = 'applicable_to';
    case ComparableTo = 'comparable_to';
    case CloselyRelatedTo = 'closely_related_to';
    case TransferableTo = 'transferable_to';
    case NotApplicable = 'not_applicable';

    /**
     * The literal English fragment rendered between the base sentence
     * and the target term.
     */
    public function render(): string
    {
        return match ($this) {
            self::ApplicableTo => 'with approaches applicable to',
            self::ComparableTo => 'comparable to',
            self::CloselyRelatedTo => 'closely related to',
            self::TransferableTo => 'with concepts transferable to',
            self::NotApplicable => '',
        };
    }
}
