<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the canonical career-data import cannot proceed safely: a
 * structural reference in the dataset doesn't resolve, or two records in
 * the dataset would collide under the same natural key. The import
 * fails loudly (and the whole run rolls back) rather than silently
 * skipping or merging ambiguous records. See docs/domain-model.md
 * "Deterministic import".
 */
class CanonicalDataImportException extends RuntimeException {}
