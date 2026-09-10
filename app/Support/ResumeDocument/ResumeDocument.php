<?php

namespace App\Support\ResumeDocument;

/**
 * The complete, presentation-ready content of one rendered resume —
 * immutable value objects only, never an Eloquent model and never a
 * raw provider/model response. Built once by GenerateResumeDocument
 * from one ResumeVariant's frozen snapshot data; an HTML preview and,
 * eventually, a PDF are both expected to be pure, deterministic
 * renderers over this tree and nothing else. See
 * docs/domain-model.md "ResumeVariant" -> "ATS Resume Renderer" for
 * the milestone this belongs to.
 *
 * Section order (Summary -> Skills -> Experience -> Selected Projects
 * -> Education) is a fixed v1 display convention — not derived from
 * anything, and NOT the same as this constructor's own param order
 * below — applied only by whatever consumes this tree (currently
 * resources/views/resume/print.blade.php). This order is a deliberate
 * product decision: Summary establishes positioning, Skills
 * immediately demonstrates target-job relevance and is highly
 * scannable, and Experience then provides proof. Selected Projects
 * sits between Experience and Education, matching Selected Projects'
 * own role as supplementary independent-project evidence rather than
 * primary Experience proof (see docs/domain-model.md "ResumeVariant"
 * -> "Selected Projects").
 */
final readonly class ResumeDocument
{
    /**
     * @param  ResumeExperienceRole[]  $experience
     * @param  ResumeSkillGroup[]  $skills  Fixed v1 category groups, in a fixed display order; each group's skill names preserve Selection's own order. See GenerateResumeDocument.
     * @param  ResumeSelectedProject[]  $selectedProjects  0-3, ordered by Selection's own relevance order; empty when none were selected.
     * @param  ResumeEducationEntry[]  $education
     */
    public function __construct(
        public ResumeContact $contact,
        public string $summary,
        public array $experience,
        public array $skills,
        public array $selectedProjects,
        public array $education,
    ) {}
}
