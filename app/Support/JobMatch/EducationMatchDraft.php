<?php

namespace App\Support\JobMatch;

use App\Enums\MatchRelationship;

/**
 * One Education reference from an already-validated provider response
 * — trusted to reference a real Education row belonging to the target
 * CareerProfile by the time this object exists. Not an Eloquent model.
 */
final readonly class EducationMatchDraft
{
    public function __construct(
        public int $educationId,
        public MatchRelationship $relationship,
        public ?string $rationale,
    ) {}
}
