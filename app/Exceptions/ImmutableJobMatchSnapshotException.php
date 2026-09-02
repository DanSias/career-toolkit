<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to update an already-persisted JobMatch,
 * JobMatchFinding, CareerFactMatch, or EducationMatch row. All four are
 * immutable snapshot rows once created — a re-run creates a new JobMatch
 * instead. Mirrors ImmutableJobAnalysisSnapshotException's role for the
 * JobAnalysis tree. See docs/job-match-generation.md.
 */
class ImmutableJobMatchSnapshotException extends RuntimeException {}
