<?php

namespace App\Enums;

/**
 * How central/repeated a finding is — independent of
 * requirement_strength. A requirement can be `required` and `low`
 * emphasis at once (explicitly de-emphasized, e.g. "React/TypeScript
 * expertise matters less than general language competence"), or
 * `preferred` and `high` emphasis (repeatedly highlighted despite being
 * technically optional). No numeric scoring — see docs/domain-model.md.
 */
enum JobAnalysisEmphasis: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
