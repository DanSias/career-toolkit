<?php

namespace App\Enums;

/**
 * How mandatory the employer considers a finding. Deliberately only 3
 * values, not 4 — "mentioned but unclassified" is represented by leaving
 * this field null, not by a 4th enum value; see docs/domain-model.md
 * "JobAnalysis" for why. `NotRequired` is a positive, explicit disclaimer
 * ("deep ERP knowledge isn't required") — distinct in kind from null,
 * which means the source is silent or the concept doesn't apply.
 */
enum JobAnalysisRequirementStrength: string
{
    case Required = 'required';
    case Preferred = 'preferred';
    case NotRequired = 'not_required';
}
