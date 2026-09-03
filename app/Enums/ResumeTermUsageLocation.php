<?php

namespace App\Enums;

/**
 * Where a ResumeVariantTargetTermUsage's claim is made — an Experience
 * bullet, or the Summary. Deliberately no `skill_group` case: qualified
 * target-term positioning is not permitted in the Skills section in v1
 * (a plain skills list is read as "things the candidate knows," and a
 * qualifying trailing clause reads as misleading there in a way it
 * doesn't in prose) — see docs/resume-variant-contract.md.
 */
enum ResumeTermUsageLocation: string
{
    case Bullet = 'bullet';
    case Summary = 'summary';
}
