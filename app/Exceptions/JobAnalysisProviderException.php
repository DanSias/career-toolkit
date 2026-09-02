<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the JobAnalysis model provider itself fails — a transport
 * error, an exhausted retry budget, a non-2xx response, or a response
 * whose content isn't decodable JSON at all. Distinct from
 * InvalidJobAnalysisResponseException, which covers a well-formed reply
 * that fails our own schema/business-rule validation. Never carries
 * credentials or raw request/response headers in its message. See
 * docs/job-analysis-generation.md.
 */
class JobAnalysisProviderException extends RuntimeException {}
