<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to update an already-persisted ResumeVariant
 * row (or any of its child rows) in place. Mirrors
 * ImmutableJobMatchSnapshotException exactly — a correction is always a
 * new ResumeVariant snapshot, never an edit to an existing one.
 */
final class ImmutableResumeVariantSnapshotException extends RuntimeException {}
