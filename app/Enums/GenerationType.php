<?php

namespace App\Enums;

/**
 * Which generation stage a GenerationAttempt row tracks. Determines
 * both what kind of subject the attempt runs against and what kind of
 * domain record a successful attempt produces:
 *
 * - JobAnalysis — subject is a JobPosting, result is a JobAnalysis.
 * - JobMatch — subject is a JobAnalysis, result is a JobMatch.
 * - ResumeVariant — subject is a JobMatch, result is a ResumeVariant
 *   (covers the whole Selection+Wording generateFull() pipeline as one
 *   attempt, not two).
 *
 * See App\Models\GenerationAttempt.
 */
enum GenerationType: string
{
    case JobAnalysis = 'job_analysis';
    case JobMatch = 'job_match';
    case ResumeVariant = 'resume_variant';
}
