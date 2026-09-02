<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a JobAnalysisFinding is about to be saved with a negative
 * years_experience_min/_max, or with years_experience_max less than
 * years_experience_min. See docs/domain-model.md "JobAnalysis".
 */
class InvalidJobAnalysisExperienceRangeException extends RuntimeException {}
