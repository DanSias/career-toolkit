{{--
    The single visual source for both the browser preview
    (GET /resume-variants/{resumeVariant}/preview) and, eventually, PDF
    export — the same markup renders both, so preview and PDF can never
    visually drift. Receives only a single `$document` variable, an
    App\Support\ResumeDocument\ResumeDocument value-object tree built
    by GenerateResumeDocument — never an Eloquent model, never raw
    domain data. Content order here is exactly ResumeDocument's own
    field order (Summary -> Experience -> Skills -> Selected Projects
    -> Education); nothing is regrouped or reordered in this template.
    The Selected Projects section is omitted entirely when empty — no
    page-fitting or hardcoded/employer-specific page break exists
    anywhere in this file.

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

    ATS v1 rendering contract: US Letter, single-column, ordinary
    top-to-bottom DOM order (never CSS `order`, floats, or absolute
    positioning to reorder content), real selectable text, standard
    `<ul><li>` bullets, conventional heading elements, no essential
    information conveyed only through color/icons/position, no
    essential content in a header/footer, no forced one-page
    constraint. CSS deliberately avoids flex/grid in favor of plain
    block/inline layout, since this same markup is expected to render
    through dompdf later, which has limited flexbox/grid support.

    Visual design: one restrained dark accent color (--accent, a single
    CSS custom property below) is used for section-heading text/rules
    and links only — never scattered as separate hex literals. Role
    and Education entries use a two-level header (primary line, then a
    secondary metadata line) rather than one run-on line; Skills groups
    put their label and skill list on separate lines. None of this
    introduces tables, grid, flexbox, columns, icons, or absolute
    positioning — every change here is typography, spacing, and the one
    accent color, on the same ordinary block/inline elements as before.
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
            padding-bottom: 0.06in;
            /* The one rule beneath the name/contact block — a subtle
               anchor for the document, not a decorative graphic. */
            border-bottom: 1pt solid var(--accent);
        }

        h1 {
            margin: 0 0 0.08in;
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
            margin: 0.16in 0 0.08in;
            font-size: 12pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--accent);
            border-bottom: 1pt solid var(--accent);
            padding-bottom: 3pt;
            /* A section heading must never be the last thing on a page —
               applies uniformly to every section's own heading, never a
               per-section override. */
            page-break-after: avoid;
            break-after: avoid;
        }

        section:first-of-type h2 {
            margin-top: 0;
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

        .role-title-line {
            margin: 0 0 0.02in;
            line-height: 1.25;
        }

        .role-title {
            font-size: 11.5pt;
            font-weight: 700;
            color: #1a1a1a;
        }

        .role-meta-line {
            margin: 0;
            font-size: 10pt;
            line-height: 1.25;
        }

        .role-employer {
            font-weight: 400;
            color: #444444;
        }

        .role-dates {
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
            margin-bottom: 0.05in;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .skill-group:last-child {
            margin-bottom: 0;
        }

        .skill-group-label {
            margin: 0 0 0.01in;
            font-size: 9.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            line-height: 1.25;
            color: #1a1a1a;
        }

        .skill-group-items {
            margin: 0;
            line-height: 1.3;
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
                $contactParts = [];
                if ($document->contact->email) {
                    $contactParts[] = $document->contact->email;
                }
                if ($document->contact->phone) {
                    $contactParts[] = $document->contact->phone;
                }
                if ($document->contact->location) {
                    $contactParts[] = $document->contact->location;
                }
            @endphp

            @if (count($contactParts) > 0 || $document->contact->portfolio || $document->contact->github)
                <p class="contact-line">
                    {{ implode(' · ', $contactParts) }}
                    @if ($document->contact->portfolio)
                        @if (count($contactParts) > 0) · @endif
                        <a href="{{ $document->contact->portfolio->url }}">{{ $document->contact->portfolio->label }}</a>
                    @endif
                    @if ($document->contact->github)
                        @if (count($contactParts) > 0 || $document->contact->portfolio) · @endif
                        <a href="{{ $document->contact->github->url }}">{{ $document->contact->github->label }}</a>
                    @endif
                </p>
            @endif
        </header>

        <section class="summary">
            <h2>Summary</h2>
            <p class="summary-text">{{ $document->summary }}</p>
        </section>

        <section class="experience">
            <h2>Experience</h2>
            @foreach ($document->experience as $role)
                <div class="role">
                    <div class="role-header">
                        <p class="role-title-line">
                            <span class="role-title">{{ $role->displayTitle }}</span>
                        </p>
                        <p class="role-meta-line">
                            <span class="role-employer">{{ $role->employerName }}</span>
                            · <span class="role-dates">{{ $role->dateRangeLabel }}</span>
                        </p>
                    </div>
                    <ul class="bullets">
                        @foreach ($role->bullets as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>

        <section class="skills">
            <h2>Skills</h2>
            @foreach ($document->skills as $group)
                <div class="skill-group">
                    <p class="skill-group-label">{{ $group->label }}</p>
                    <p class="skill-group-items">{{ implode(' · ', $group->skills) }}</p>
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
