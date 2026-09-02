<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a CareerFact is about to be saved with an invalid
 * `attributable_*` pairing: an unsupported morph type, a nonexistent
 * target, or a target belonging to a different CareerProfile than the
 * fact itself. See docs/domain-model.md.
 */
class InvalidCareerFactAttributionException extends RuntimeException {}
