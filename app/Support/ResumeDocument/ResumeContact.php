<?php

namespace App\Support\ResumeDocument;

/**
 * The resume header's contact block. `name` always comes from the
 * CareerProfile that owns the ResumeVariant; every other field is
 * nullable — no source establishes phone, location, portfolio, or
 * GitHub URLs today (see docs/canonical-data-proposal.md, 2026-09-09
 * update). Deliberately always resolved live from the current
 * CareerProfile, never frozen at generation time: unlike Experience/
 * Skills/Education (which describe historical, evidenced work and
 * must stay exactly what was true and approved at generation time),
 * contact information describes the present — a resume should always
 * show the candidate's current phone/location/links, not a historical
 * snapshot of them.
 */
final readonly class ResumeContact
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $location,
        public ?ResumeLink $portfolio,
        public ?ResumeLink $github,
    ) {}
}
