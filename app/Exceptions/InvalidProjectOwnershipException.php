<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Project is about to be saved with an invalid ownership
 * shape: a `role_id` that does not resolve to a real Role, a `role_id`
 * whose owning CareerProfile does not match the Project's own
 * `career_profile_id`, or a `career_profile_id` that could not be
 * resolved at all (no `role_id` to derive it from, and none supplied
 * explicitly — required for an independent Project). See
 * docs/domain-model.md "Project ownership".
 */
class InvalidProjectOwnershipException extends RuntimeException {}
