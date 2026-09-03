<?php

namespace App\Support\ResumeVariant;

/**
 * One canonical Skill selected for the Skills section, and its display
 * order. Grouping label is never part of this draft — it's computed
 * deterministically from the Skill's own canonical `category` at
 * render time, never generated.
 */
final readonly class SkillSelectionDraft
{
    public function __construct(
        public int $skillId,
        public int $order,
    ) {}
}
