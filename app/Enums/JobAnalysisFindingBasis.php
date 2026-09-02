<?php

namespace App\Enums;

/**
 * How directly the source text grounds a finding's existence/content —
 * independent of App\Enums\JobAnalysisRequirementStrength, which answers
 * a different question (how mandatory the employer considers it). See
 * docs/domain-model.md "JobAnalysis" for the worked example proving these
 * two fields never encode the same uncertainty.
 */
enum JobAnalysisFindingBasis: string
{
    case Explicit = 'explicit';
    case StronglyImplied = 'strongly_implied';
    case Inferred = 'inferred';
}
