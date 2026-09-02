<?php

namespace App\Support\JobMatch;

/**
 * The complete, trusted result of one matching run: an
 * already-validated provider response, ready to be persisted as one
 * immutable JobMatch snapshot. Not an Eloquent model — see
 * GenerateJobMatch, the only place this is ever turned into database
 * rows.
 */
final readonly class JobMatchDraft
{
    /**
     * @param  array<int, JobMatchFindingDraft>  $findings
     */
    public function __construct(
        public array $findings,
    ) {}
}
