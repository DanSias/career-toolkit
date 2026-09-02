<?php

namespace App\Enums;

/**
 * A synthesized, whole-posting judgment — not a citation of one passage.
 * Always inferred by definition (see JobAnalysis::$overall_seniority);
 * there is no `basis` field for it because it can't be anything other
 * than inferred.
 */
enum JobAnalysisSeniority: string
{
    case Junior = 'junior';
    case Mid = 'mid';
    case Senior = 'senior';
    case StaffOrAbove = 'staff_or_above';
    case Unspecified = 'unspecified';
}
