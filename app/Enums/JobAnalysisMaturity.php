<?php

namespace App\Enums;

/**
 * Distinguishes production experience from prototyping/experimentation —
 * the single most explicit, repeated distinction in the design corpus
 * ("not occasional experimentation", "in production, not just
 * prototypes"). Deliberately general-purpose, not AI-specific in name,
 * since the same distinction applies to any technology/capability claim.
 * Mainly relevant to `technology` and `capability` findings.
 */
enum JobAnalysisMaturity: string
{
    case Production = 'production';
    case PrototypeOrExperimental = 'prototype_or_experimental';
    case Unspecified = 'unspecified';
}
