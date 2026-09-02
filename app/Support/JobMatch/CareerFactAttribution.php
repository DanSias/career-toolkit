<?php

namespace App\Support\JobMatch;

use App\Models\CareerFact;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;

/**
 * Resolves a CareerFact's polymorphic `attributable` target into plain
 * employer/role/project names and, when the chain passes through a
 * Role, that Role's raw date context. Shared by CandidatePayloadBuilder
 * (building the provider-facing payload) and the JobMatch review UI
 * (displaying live attribution alongside a match) — the same
 * resolution logic, two different consumers.
 *
 * Role dates here are orienting context only, never a basis for
 * computing a duration — see docs/job-match-contract.md
 * "Years-of-experience strategy".
 */
final readonly class CareerFactAttribution
{
    public function __construct(
        public ?string $employer,
        public ?string $role,
        public ?string $project,
        public ?int $roleStartYear,
        public ?int $roleStartMonth,
        public ?int $roleEndYear,
        public ?int $roleEndMonth,
    ) {}

    /**
     * Requires `attributable` (and, for Role/Project, `employer`/
     * `role.employer`) to already be loaded on $fact to avoid N+1
     * queries when resolving many facts at once.
     */
    public static function resolve(CareerFact $fact): self
    {
        $attributable = $fact->attributable;

        if ($attributable instanceof Employer) {
            return new self(
                employer: $attributable->name,
                role: null,
                project: null,
                roleStartYear: null,
                roleStartMonth: null,
                roleEndYear: null,
                roleEndMonth: null,
            );
        }

        if ($attributable instanceof Role) {
            return new self(
                employer: $attributable->employer?->name,
                role: $attributable->title,
                project: null,
                roleStartYear: $attributable->start_year,
                roleStartMonth: $attributable->start_month,
                roleEndYear: $attributable->end_year,
                roleEndMonth: $attributable->end_month,
            );
        }

        if ($attributable instanceof Project) {
            $role = $attributable->role;

            return new self(
                employer: $role?->employer?->name,
                role: $role?->title,
                project: $attributable->name,
                roleStartYear: $role?->start_year,
                roleStartMonth: $role?->start_month,
                roleEndYear: $role?->end_year,
                roleEndMonth: $role?->end_month,
            );
        }

        // CareerProfile-direct attribution: no employer/role/project
        // context, and no role dates.
        return new self(
            employer: null,
            role: null,
            project: null,
            roleStartYear: null,
            roleStartMonth: null,
            roleEndYear: null,
            roleEndMonth: null,
        );
    }
}
