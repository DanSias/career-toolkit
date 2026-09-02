<?php

namespace App\Support\JobMatch;

use App\Enums\MatchRelationship;

/**
 * One CareerFact reference from an already-validated provider response
 * — trusted to reference a real CareerFact belonging to the target
 * CareerProfile by the time this object exists. Not an Eloquent model;
 * see GenerateJobMatch for where this is finally persisted.
 */
final readonly class CareerFactMatchDraft
{
    public function __construct(
        public string $careerFactKey,
        public MatchRelationship $relationship,
        public ?string $rationale,
    ) {}
}
