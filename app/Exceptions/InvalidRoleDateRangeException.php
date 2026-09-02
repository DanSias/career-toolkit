<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Role is about to be saved with an invalid date
 * combination: an out-of-range month, an end month with no end year, or
 * an end year/month before the start year/month. See docs/domain-model.md.
 */
class InvalidRoleDateRangeException extends RuntimeException {}
