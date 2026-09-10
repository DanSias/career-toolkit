<?php

namespace App\Support\ResumeDocument;

/**
 * The resume header's contact block. `name` always comes from the
 * CareerProfile that owns the ResumeVariant; every other field is
 * nullable, since not every CareerProfile establishes phone, location,
 * portfolio, or GitHub. This DTO always carries every field CareerData
 * has — GitHub and location are deliberately NOT rendered in the
 * default v1 resume header, but that is a rendering decision made in
 * resources/views/resume/print.blade.php, not something this DTO or
 * GenerateResumeDocument narrows. Deliberately always resolved live
 * from the current CareerProfile, never frozen at generation time:
 * unlike Experience/Skills/Education (which describe historical,
 * evidenced work and must stay exactly what was true and approved at
 * generation time), contact information describes the present — a
 * resume should always show the candidate's current phone/location/
 * links, not a historical snapshot of them.
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
