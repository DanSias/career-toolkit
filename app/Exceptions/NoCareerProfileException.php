<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Support\CurrentCareerProfile::resolve() when no
 * CareerProfile exists yet. Callers that genuinely require an owning
 * profile (e.g. creating a JobPosting) should let this propagate rather
 * than silently proceeding with no profile — see
 * docs/domain-model.md "Current CareerProfile resolution".
 */
class NoCareerProfileException extends RuntimeException {}
