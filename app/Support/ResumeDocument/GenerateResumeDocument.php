<?php

namespace App\Support\ResumeDocument;

use App\Enums\SkillCategory;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceRole;
use App\Models\ResumeVariantProject;
use App\Models\ResumeVariantSkillSelection;
use App\Models\Skill;
use App\Support\CareerData\RoleDateFormatter;

/**
 * Assembles a presentation-ready ResumeDocument from one ResumeVariant
 * — pure and deterministic, no AI/provider calls, no live re-resolution
 * of Employer/Role/Skill/Education CONTENT: every substantive field
 * this reads is already frozen on the ResumeVariant's own snapshot
 * tree (see docs/domain-model.md "ResumeVariant" -> "Experience role
 * snapshots"). There are two deliberate exceptions, neither of which
 * can change what is displayed, only contact reachability or
 * presentation organization: contact information, always read live
 * from the owning CareerProfile (see ResumeContact's own docblock),
 * and — as of the v2 Skills presentation grouping — each selected
 * skill's canonical Skill.slug, read live purely to choose which
 * presentation group (see buildSkillGroups()'s own docblock) an
 * already-frozen skill name renders under.
 *
 * Section order (Summary -> Experience -> Skills -> Selected Projects
 * -> Education) is fixed by ResumeDocument's own field order — not
 * computed here.
 */
final class GenerateResumeDocument
{
    /**
     * Fixed v2 presentation groups and display order — a group with
     * zero selected skills is simply skipped, never rendered empty.
     * ::BuildTechnology and ::PlatformIntegration fall straight through
     * to their own renamed group (every skill in those two canonical
     * categories, no exceptions); ::Capability and ::Practice are both
     * broad enough on their own to mix AI/developer-tooling evidence
     * with marketing-domain evidence in one undifferentiated bucket
     * (e.g. "AI-assisted development" and "Lead generation" were both
     * simply "Engineering"/"Workflow" before this split existed) — see
     * presentationGroup()'s own docblock for how those two are divided
     * between GROUP_ENGINEERING and GROUP_MARKETING_TECHNOLOGY.
     * SkillCategory's own enum cases remain the real domain concept and
     * are never renamed; these are rendering labels only.
     */
    private const string GROUP_LANGUAGES_AND_FRAMEWORKS = 'Languages & Frameworks';

    private const string GROUP_DATA_AND_PLATFORMS = 'Data & Platforms';

    private const string GROUP_ENGINEERING = 'Engineering';

    private const string GROUP_MARKETING_TECHNOLOGY = 'Marketing Technology';

    /**
     * @var array<int, string>
     */
    private const array GROUP_ORDER = [
        self::GROUP_LANGUAGES_AND_FRAMEWORKS,
        self::GROUP_DATA_AND_PLATFORMS,
        self::GROUP_ENGINEERING,
        self::GROUP_MARKETING_TECHNOLOGY,
    ];

    /**
     * Skill.slug values (never Skill.name — a display label a maintainer
     * may freely reword without meaning to reclassify the skill) that
     * present under "Marketing Technology" instead of the ::Capability/
     * ::Practice default of "Engineering". Deliberately an explicit
     * allow-list, not an exclude-list: an unrecognized ::Capability/
     * ::Practice skill — including any not-yet-invented future one —
     * always falls to the safer, more general "Engineering" default
     * rather than being silently dropped or guessed into this bucket.
     * A/B testing is included here despite its canonical ::Practice
     * category (a workflow-practice-shaped concept) because it is, in
     * substance, a marketing/growth experimentation practice, not an
     * engineering one — an example of why this is a slug allow-list,
     * not a blanket category fold.
     *
     * @var array<int, string>
     */
    private const array MARKETING_TECHNOLOGY_SLUGS = [
        'campaign-attribution',
        'content-strategy',
        'conversion-rate-optimization',
        'ecommerce-integration',
        'landing-page-development',
        'lead-generation',
        'marketing-analytics',
        'marketing-automation',
        'paid-acquisition',
        'sem',
        'seo',
        'technical-seo',
        'ab-testing',
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
        $selections = $variant->skillSelections->sortBy('display_order')->values();

        // The one deliberate, narrow exception to this class's own
        // "frozen snapshot only" rule (see class docblock), exactly
        // mirroring ResumeContact's own live CareerProfile read: a
        // skill's canonical Skill.slug is read live, via its frozen
        // skill_id, purely to decide which PRESENTATION GROUP its
        // already-frozen name/order renders under — it can never
        // change what is displayed (that stays 100% ResumeVariant-
        // SkillSelection's own frozen name/category/display_order),
        // only where it is organized. ResumeVariantSkillSelection
        // itself carries no slug column (this milestone deliberately
        // does not add one — see docs/resume-variant-generation.md
        // "PDF export"), so this is the only way to key grouping off a
        // stable identifier rather than the mutable display name.
        $slugsById = Skill::query()
            ->whereIn('id', $selections->pluck('skill_id'))
            ->pluck('slug', 'id');

        $selectionsByGroup = $selections->groupBy(
            fn (ResumeVariantSkillSelection $selection) => $this->presentationGroup(
                $selection->category,
                $slugsById->get($selection->skill_id)
            )
        );

        $groups = [];

        foreach (self::GROUP_ORDER as $label) {
            $selectionsInGroup = $selectionsByGroup->get($label);

            if ($selectionsInGroup === null || $selectionsInGroup->isEmpty()) {
                continue;
            }

            $groups[] = new ResumeSkillGroup($label, $selectionsInGroup->pluck('name')->all());
        }

        return $groups;
    }

    /**
     * ::BuildTechnology and ::PlatformIntegration always fall straight
     * through to their own renamed group. ::Capability and ::Practice
     * both default to "Engineering" unless the skill's own slug is on
     * the MARKETING_TECHNOLOGY_SLUGS allow-list, in which case it
     * presents under "Marketing Technology" instead — an unrecognized
     * slug (including a null one, e.g. if a live Skill row were ever
     * somehow unresolvable) safely defaults to "Engineering" rather
     * than being dropped.
     */
    private function presentationGroup(string $category, ?string $slug): string
    {
        return match ($category) {
            SkillCategory::BuildTechnology->value => self::GROUP_LANGUAGES_AND_FRAMEWORKS,
            SkillCategory::PlatformIntegration->value => self::GROUP_DATA_AND_PLATFORMS,
            default => $slug !== null && in_array($slug, self::MARKETING_TECHNOLOGY_SLUGS, true)
                ? self::GROUP_MARKETING_TECHNOLOGY
                : self::GROUP_ENGINEERING,
        };
    }

    private function transformExperienceRole(ResumeVariantExperienceRole $role): ResumeExperienceRole
    {
        $dates = RoleDateFormatter::formatRangeAbbreviated($role->start_year, $role->start_month, $role->end_year, $role->end_month);

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
