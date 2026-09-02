<?php

namespace App\Enums;

/**
 * How much a CareerFact is trusted, independent of where it came from.
 *
 * Kept separate from evidence/provenance: a fact can have strong evidence
 * and still be unverified, or be verified with thin evidence (e.g. a user
 * confirming a fact directly).
 */
enum Verification: string
{
    case Verified = 'verified';
    case StronglySupported = 'strongly_supported';
    case NeedsConfirmation = 'needs_confirmation';
}
