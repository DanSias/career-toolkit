<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to update a JobAnalysis, JobAnalysisFinding,
 * or JobAnalysisFindingEvidence row after it has been created. A
 * JobAnalysis is an immutable, validated snapshot — corrections or
 * re-analysis create a new JobAnalysis, never mutate an existing one.
 * See docs/domain-model.md "JobAnalysis".
 */
class ImmutableJobAnalysisSnapshotException extends RuntimeException {}
