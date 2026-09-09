<?php

namespace App\Support\ResumeDocument;

use App\Enums\SkillCategory;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceRole;
use App\Models\ResumeVariantProject;
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
 * Section order (Summary -> Experience -> Skills -> Selected Projects
 * -> Education) is fixed by ResumeDocument's own field order — not
 * computed here.
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
            'projects.bullets',
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
            selectedProjects: $variant->projects
                ->sortBy('display_order')
                ->map($this->transformSelectedProject(...))
                ->values()
                ->all(),
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

    /**
     * v1 carries exactly one bullet per ResumeVariantProject — see
     * docs/domain-model.md "ResumeVariant" -> "Selected Projects" —
     * read from the frozen snapshot, never from a live Project/Skill.
     *
     * Link labels are fixed, purpose-describing text ("Live Demo",
     * "Repository") rather than the URL-derived label `link()`/
     * ResumeLinkFormatter produce for the contact header — the
     * surrounding context here already makes each link's purpose
     * obvious, so a repeated domain name would be redundant.
     */
    private function transformSelectedProject(ResumeVariantProject $project): ResumeSelectedProject
    {
        return new ResumeSelectedProject(
            name: $project->name,
            technologies: $project->technology_names,
            bullet: $project->bullets->sortBy('display_order')->first()->text,
            liveDemo: $project->live_url === null ? null : new ResumeLink($project->live_url, 'Live Demo'),
            repository: $project->repository_url === null ? null : new ResumeLink($project->repository_url, 'Repository'),
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
