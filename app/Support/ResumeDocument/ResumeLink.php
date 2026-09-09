<?php

namespace App\Support\ResumeDocument;

/**
 * One rendered contact link: the real URL (for the href) plus a short
 * display label (e.g. "github.com/danielsias" for
 * "https://github.com/danielsias") — see ResumeLinkFormatter for how
 * the label is derived.
 */
final readonly class ResumeLink
{
    public function __construct(
        public string $url,
        public string $label,
    ) {}
}
