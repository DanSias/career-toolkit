{{--
    The single visual source for both the browser preview
    (GET /resume-variants/{resumeVariant}/preview) and, eventually, PDF
    export — the same markup renders both, so preview and PDF can never
    visually drift. Receives only a single `$document` variable, an
    App\Support\ResumeDocument\ResumeDocument value-object tree built
    by GenerateResumeDocument — never an Eloquent model, never raw
    domain data. Content order here is a fixed v1 display convention —
    Header -> Summary -> Skills -> Experience -> Selected Projects ->
    Education — declared only here (see ResumeDocument's own docblock:
    its constructor's param order is deliberately NOT the render
    order). The Selected Projects section is omitted entirely when
    empty — no page-fitting or hardcoded/employer-specific page break
    exists anywhere in this file.

    Pagination model: content is allowed to flow across pages freely
    except where a *small* semantic unit would otherwise be visually
    broken — one bullet, one Education entry, one Selected Project
    entry, the Summary paragraph, a Skills category line, and (via a
    heading-glued-to-next-content rule) every section/Role heading
    together with whatever immediately follows it. A Role itself is
    deliberately NOT one atomic unit: a Role with several bullets may
    span a page boundary between bullets, with its heading always
    staying with at least its first bullet. See the `break-*`/
    `page-break-*` rules below for exactly where each invariant lives;
    none of them reference a specific employer, Role, or page number.

    ATS v1 rendering contract: US Letter, single-column, real
    selectable text, standard `<ul><li>` bullets, conventional heading
    elements, no essential information conveyed only through color/
    icons/position, no essential content in the printed page header/
    footer, no forced one-page constraint, no tables, no absolute
    positioning. DOM/text order (what a plain-text extractor or CSS-off
    view sees) always matches a sane reading order — see .role-header
    below for the one place layout visually reflows a line (title+date
    sharing a row) while keeping DOM order title -> company -> date via
    ordinary flexbox `order`, never floats or absolute positioning. CSS
    uses a small amount of flexbox for exactly two things — the
    centered section-heading rule and that role-header line layout —
    everything else stays plain block/inline; this file still targets
    an eventual dompdf render, which has partial (not full) flexbox
    support, so flexbox use here stays intentionally minimal.

    Visual design: one restrained dark accent color (--accent, a single
    CSS custom property below) is used for section-heading text/rules
    and links only — never scattered as separate hex literals. Section
    headings are centered conventional labels flanked by a decorative
    accent rule (pure CSS ::before/::after, no injected dash/line
    characters in the actual heading text). There is deliberately no
    visible Summary heading — the summary paragraph reads directly
    beneath the centered name/contact header — while the Summary
    section/DTO field itself is unchanged. Role and Education entries
    use a two-level header (primary line, then a secondary metadata
    line); each Skills group is one compact "Label: skill · skill"
    line — the label stays visually distinguishable via font-weight
    only, never consuming its own row. None of this introduces tables,
    grid, columns, icons, or absolute positioning.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document->contact->name }} — Resume</title>
    <style>
        :root {
            /* The one accent color this document uses — section
               headings, section rules, and links. Every other use of
               this color below is var(--accent), never a repeated hex
               literal. */
            --accent: #1a4d8f;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            /* Neutral page background so the on-screen preview reads as
               a sheet of paper — deliberately overridden to plain white
               under @media print below, so this never prints. */
            background: #e9e9e9;
            color: #1a1a1a;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
        }

        .page {
            width: 8.5in;
            min-height: 11in;
            margin: 2rem auto;
            padding: 0.75in;
            background: #ffffff;
            box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.08), 0 4px 18px rgba(0, 0, 0, 0.10);
        }

        @media print {
            body {
                background: #ffffff;
            }

            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0.75in;
                box-shadow: none;
            }
        }

        header {
            margin: 0 0 0.12in;
            padding-bottom: 0.08in;
            /* Centered anchor for the document — name, then compact
               contact info beneath, with one subtle accent rule below;
               not a decorative graphic. */
            text-align: center;
            border-bottom: 1pt solid var(--accent);
        }

        h1 {
            margin: 0 0 0.06in;
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: 0.01em;
            color: #1a1a1a;
        }

        .contact-line {
            margin: 0;
            font-size: 10pt;
            color: #444444;
        }

        .contact-line a {
            color: var(--accent);
            text-decoration: none;
        }

        .contact-line a:hover {
            text-decoration: underline;
        }

        h2 {
            margin: 0.22in 0 0.1in;
            font-size: 12pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--accent);
            /* Centered conventional label flanked by a decorative accent
               rule on both sides — ::before/::after are empty, purely
               CSS lines, never characters injected into the heading's
               own text/DOM content. */
            display: flex;
            align-items: center;
            text-align: center;
            /* A section heading must never be the last thing on a page —
               applies uniformly to every section's own heading, never a
               per-section override. */
            page-break-after: avoid;
            break-after: avoid;
        }

        h2::before,
        h2::after {
            content: "";
            flex: 1 1 auto;
            border-top: 1pt solid var(--accent);
            margin: 0 0.18in;
        }

        /* The heading of whatever section immediately follows Summary
           (Skills in the current v1 order) sits directly beneath a
           plain paragraph rather than another heading/rule, so it gets
           a tighter top margin — a generic "first heading after
           Summary" rule, not a Skills-specific one. */
        section.summary + section h2 {
            margin-top: 0.08in;
        }

        .summary-text {
            margin: 0;
            /* A short paragraph should never be split mid-sentence across
               a page boundary — the same "protect small semantic units"
               principle applied everywhere else in this file. */
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .role {
            margin-bottom: 0.18in;
            /* Deliberately NOT page-break-inside/break-inside: avoid here
               — a Role with several bullets is explicitly allowed to span
               pages (see .role-header and ul.bullets li below for the
               actual invariants: the heading stays with its first bullet,
               and every individual bullet stays whole; only the "treat
               the whole Role as one atomic block" behavior is removed). */
        }

        .role:last-child {
            margin-bottom: 0;
        }

        .role-header {
            margin: 0 0 0.06in;
            /* Keeps the heading glued to whatever follows (the bullet
               list); combined with ul.bullets li's own break-inside:avoid
               below, this transitively keeps the heading with its first
               bullet without needing a role-specific selector. */
            page-break-after: avoid;
            break-after: avoid;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .role-header-row {
            /* Visually reflows title+date onto a shared first line
               (date right-aligned) with company on its own second
               line, while DOM/text order stays title -> company ->
               date (see the docblock at the top of this file) — the
               `order` values below are purely visual, and `role-title`/
               `role-employer`/`role-dates` are ordinary <p> elements so
               a CSS-off view already reads as three stacked lines in
               that same order. flex-wrap allows graceful wrapping
               instead of overlap/truncation when a title is long. */
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            column-gap: 0.2in;
        }

        .role-title {
            order: 1;
            margin: 0;
            font-size: 11.5pt;
            font-weight: 700;
            line-height: 1.25;
            color: #1a1a1a;
        }

        .role-dates {
            order: 2;
            margin: 0 0 0 auto;
            font-size: 10pt;
            line-height: 1.25;
            color: #444444;
            white-space: nowrap;
        }

        .role-employer {
            order: 3;
            flex-basis: 100%;
            margin: 0.02in 0 0;
            font-size: 10pt;
            line-height: 1.25;
            font-weight: 400;
            color: #444444;
        }

        ul.bullets {
            margin: 0;
            padding-left: 0.22in;
        }

        ul.bullets li {
            margin-bottom: 0.05in;
            /* Each bullet is an indivisible unit — never split mid-bullet
               across a page. Applies uniformly to Experience bullets and
               Selected Project bullets (both share this class); a Role
               itself may still span pages between bullets. */
            page-break-inside: avoid;
            break-inside: avoid;
        }

        ul.bullets li:last-child {
            margin-bottom: 0;
        }

        .skill-group {
            margin: 0 0 0.06in;
            /* Compact, single-line presentation: "Label: skill · skill"
               — the label stays visually distinguishable via font-weight
               alone, never consuming its own vertical row. */
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .skill-group:last-child {
            margin-bottom: 0;
        }

        .skill-group-label {
            font-weight: 700;
        }

        .selected-project {
            margin-bottom: 0.18in;
            /* Unlike .role, a selected-project entry is always small and
               intentionally compact (name/technology line/one bullet/
               links) — kept fully atomic, never split across pages. */
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .selected-project:last-child {
            margin-bottom: 0;
        }

        .selected-project-name-line {
            margin: 0 0 0.02in;
            line-height: 1.25;
        }

        .selected-project-name {
            font-weight: 700;
            color: #1a1a1a;
        }

        .selected-project-meta-line {
            margin: 0 0 0.05in;
            font-size: 10pt;
            line-height: 1.25;
            color: #444444;
        }

        .selected-project-links {
            margin: 0.04in 0 0;
            font-size: 10pt;
        }

        .selected-project-links a {
            color: var(--accent);
            text-decoration: none;
        }

        .selected-project-links a:hover {
            text-decoration: underline;
        }

        .education-entry {
            margin-bottom: 0.08in;
            /* Each entry stays whole, but entries are never grouped
               together as a block — a later entry may start a new page
               on its own while an earlier one stays on the previous
               page. The Education heading itself is protected from being
               stranded without its first entry by the generic h2 rule
               above, not by anything section-specific here. */
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .education-entry:last-child {
            margin-bottom: 0;
        }

        .education-degree-line {
            margin: 0 0 0.02in;
            line-height: 1.25;
        }

        .education-degree {
            font-weight: 700;
            color: #1a1a1a;
        }

        .education-meta-line {
            margin: 0;
            font-size: 10pt;
            line-height: 1.25;
        }

        .education-institution {
            font-weight: 400;
            color: #444444;
        }

        .education-dates {
            color: #444444;
        }
    </style>
</head>
<body>
    <div class="page">
        <header>
            <h1>{{ $document->contact->name }}</h1>

            @php
                // Default v1 contact order is exactly email -> phone ->
                // portfolio. Location and GitHub are deliberately not
                // rendered here — a rendering decision only, both stay
                // on ResumeContact/CareerProfile untouched (see
                // App\Support\ResumeDocument\ResumeContact's docblock).
                $contactParts = [];
                if ($document->contact->email) {
                    $contactParts[] = $document->contact->email;
                }
                if ($document->contact->phone) {
                    $contactParts[] = $document->contact->phone;
                }
            @endphp

            @if (count($contactParts) > 0 || $document->contact->portfolio)
                <p class="contact-line">
                    {{ implode(' · ', $contactParts) }}
                    @if ($document->contact->portfolio)
                        @if (count($contactParts) > 0) · @endif
                        <a href="{{ $document->contact->portfolio->url }}">{{ $document->contact->portfolio->label }}</a>
                    @endif
                </p>
            @endif
        </header>

        <section class="summary">
            <p class="summary-text">{{ $document->summary }}</p>
        </section>

        <section class="skills">
            <h2>Technical Skills</h2>
            @foreach ($document->skills as $group)
                <p class="skill-group">
                    <span class="skill-group-label">{{ $group->label }}:</span>
                    {{ implode(' · ', $group->skills) }}
                </p>
            @endforeach
        </section>

        <section class="experience">
            <h2>Professional Experience</h2>
            @foreach ($document->experience as $role)
                <div class="role">
                    <div class="role-header">
                        <div class="role-header-row">
                            <p class="role-title">{{ $role->displayTitle }}</p>
                            <p class="role-employer">{{ $role->employerName }}</p>
                            <p class="role-dates">{{ $role->dateRangeLabel }}</p>
                        </div>
                    </div>
                    <ul class="bullets">
                        @foreach ($role->bullets as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>

        @if (count($document->selectedProjects) > 0)
            <section class="selected-projects">
                <h2>Selected Projects</h2>
                @foreach ($document->selectedProjects as $project)
                    <div class="selected-project">
                        <p class="selected-project-name-line">
                            <span class="selected-project-name">{{ $project->name }}</span>
                        </p>
                        @if (count($project->technologies) > 0)
                            <p class="selected-project-meta-line">{{ implode(', ', $project->technologies) }}</p>
                        @endif
                        <ul class="bullets">
                            <li>{{ $project->bullet }}</li>
                        </ul>
                        @if ($project->liveDemo || $project->repository)
                            <p class="selected-project-links">
                                @if ($project->liveDemo)
                                    <a href="{{ $project->liveDemo->url }}">{{ $project->liveDemo->label }}</a>
                                @endif
                                @if ($project->liveDemo && $project->repository) · @endif
                                @if ($project->repository)
                                    <a href="{{ $project->repository->url }}">{{ $project->repository->label }}</a>
                                @endif
                            </p>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        <section class="education">
            <h2>Education</h2>
            @foreach ($document->education as $entry)
                <div class="education-entry">
                    <p class="education-degree-line">
                        <span class="education-degree">{{ $entry->degree }}{{ $entry->fieldOfStudy ? ', '.$entry->fieldOfStudy : '' }}</span>
                    </p>
                    <p class="education-meta-line">
                        <span class="education-institution">{{ $entry->institution }}</span>
                        · <span class="education-dates">{{ $entry->dateRangeLabel }}</span>
                    </p>
                </div>
            @endforeach
        </section>
    </div>
</body>
</html>
