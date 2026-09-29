<?php

namespace App\Enums;

/**
 * An Application's real, mutable operational status — unlike every
 * other generated/derived entity in this codebase, this is not a
 * snapshot version; it genuinely changes in place, since it describes
 * an ongoing real-world process. Currently only Draft ("I am actively
 * evaluating or pursuing this opportunity," broad enough to cover
 * inspection itself) — a submitted, rejected, or withdrawn application
 * arrives as a one-line case addition once the feature producing it
 * exists, never a migration. See docs/domain-model.md "Application".
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
}
