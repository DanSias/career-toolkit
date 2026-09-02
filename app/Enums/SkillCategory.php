<?php

namespace App\Enums;

/**
 * What kind of skill a Skill record represents.
 *
 * Distinguishing these matters: a build technology, a platform integration,
 * an engineering capability, and a practice/workflow carry different kinds
 * of evidence and shouldn't be flattened into one undifferentiated list.
 */
enum SkillCategory: string
{
    case BuildTechnology = 'build_technology';
    case PlatformIntegration = 'platform_integration';
    case Capability = 'capability';
    case Practice = 'practice';
}
