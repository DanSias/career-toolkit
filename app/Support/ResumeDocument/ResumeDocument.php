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
 * Section order (Summary -> Experience -> Skills -> Selected Projects
 * -> Education) is a fixed, declared convention — not derived from
 * anything — applied by whatever consumes this tree, not encoded as a
 * field here. Selected Projects sits between Skills and Education,
 * matching the two-page composition direction (Skills is page-1
 * content; Selected Projects and Education are both page-2 content —
 * see docs/domain-model.md "ResumeVariant" -> "Selected Projects").
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
