<?php

namespace App\Support\ResumeDocument;

use App\Enums\SkillCategory;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceRole;
use App\Support\CareerData\RoleDateFormatter;

/**
 * Assembles a presentation-ready ResumeDocument from one ResumeVariant
 * — pure and deterministic, no AI/provider calls, no live re-resolution
 * of Employer/Role/Skill/Education content: every substantive field
 * this reads is already frozen on the ResumeVariant's own snapshot
 * tree (see docs/domain-model.md "ResumeVariant" -> "Experience role
 * snapshots"). The one deliberate exception is contact information,
 * which is always read live from the owning CareerProfile — see
 * ResumeContact's own docblock for why that asymmetry is correct
 * rather than an oversight.
 *
 * Section order (Summary -> Experience -> Skills -> Education) is
 * fixed by ResumeDocument's own field order — not computed here.
 */
final class GenerateResumeDocument
{
    /**
     * Fixed v1 presentation labels and group display order — a category
     * with zero selected skills is simply skipped, never rendered empty.
     *
     * @var array<string, string>
     */
    private const CATEGORY_LABELS = [
        SkillCategory::BuildTechnology->value => 'Technologies',
        SkillCategory::PlatformIntegration->value => 'Platforms & Tools',
        SkillCategory::Capability->value => 'Capabilities',
        SkillCategory::Practice->value => 'Practices',
    ];

    public function generate(ResumeVariant $variant): ResumeDocument
    {
        $variant->loadMissing([
            'careerProfile',
            'experienceRoles.bullets',
            'skillSelections',
            'educationSelections',
        ]);

        $profile = $variant->careerProfile;

        return new ResumeDocument(
            contact: new ResumeContact(
                name: $profile->name,
                email: $profile->email,
                phone: $profile->phone,
                location: $profile->location,
                portfolio: $this->link($profile->portfolio_url),
                github: $this->link($profile->github_url),
            ),
            summary: $variant->summary ?? '',
            experience: $variant->experienceRoles
                ->sortBy('display_order')
                ->map($this->transformExperienceRole(...))
                ->values()
                ->all(),
            skills: $this->buildSkillGroups($variant),
            education: $variant->educationSelections
                ->sortBy('display_order')
                ->map(fn ($selection) => new ResumeEducationEntry(
                    degree: $selection->degree,
                    fieldOfStudy: $selection->field_of_study,
                    institution: $selection->institution,
                    dateRangeLabel: $this->educationDateRangeLabel($selection->start_year, $selection->end_year),
                ))
                ->values()
                ->all(),
        );
    }

    /**
     * @return ResumeSkillGroup[]
     */
    private function buildSkillGroups(ResumeVariant $variant): array
    {
        $selectionsByCategory = $variant->skillSelections
            ->sortBy('display_order')
            ->groupBy('category');

        $groups = [];

        foreach (self::CATEGORY_LABELS as $category => $label) {
            $selections = $selectionsByCategory->get($category);

            if ($selections === null || $selections->isEmpty()) {
                continue;
            }

            $groups[] = new ResumeSkillGroup($label, $selections->pluck('name')->all());
        }

        return $groups;
    }

    private function transformExperienceRole(ResumeVariantExperienceRole $role): ResumeExperienceRole
    {
        $dates = RoleDateFormatter::formatRange($role->start_year, $role->start_month, $role->end_year, $role->end_month);

        return new ResumeExperienceRole(
            employerName: $role->employer_name,
            displayTitle: $role->display_title,
            dateRangeLabel: $dates['label'],
            isCurrent: $dates['is_current'],
            bullets: $role->bullets->sortBy('display_order')->pluck('text')->all(),
        );
    }

    private function link(?string $url): ?ResumeLink
    {
        return $url === null ? null : new ResumeLink($url, ResumeLinkFormatter::label($url));
    }

    /**
     * Education is year-only precision (see docs/domain-model.md
     * "Education") — no month component to format, unlike Role dates.
     */
    private function educationDateRangeLabel(?int $startYear, ?int $endYear): string
    {
        if ($startYear === null) {
            return $endYear === null ? '' : (string) $endYear;
        }

        return $endYear === null ? (string) $startYear : "{$startYear} – {$endYear}";
    }
}
