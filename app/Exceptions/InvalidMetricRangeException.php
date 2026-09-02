<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Metric is about to be saved with `value_max` set below
 * `value` — an invalid bounded range. See docs/domain-model.md.
 */
class InvalidMetricRangeException extends RuntimeException {}
