<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the JobMatch model provider itself fails — a transport
 * error, an exhausted retry budget, a non-2xx response, or a response
 * whose content isn't decodable JSON at all. Distinct from
 * InvalidJobMatchResponseException, which covers a well-formed reply
 * that fails our own schema/business-rule validation. Never carries
 * credentials or raw request/response headers in its message. Mirrors
 * JobAnalysisProviderException's role. See docs/job-match-generation.md.
 */
class JobMatchProviderException extends RuntimeException {}
