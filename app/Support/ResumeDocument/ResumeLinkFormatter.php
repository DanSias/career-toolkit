<?php

namespace App\Support\ResumeDocument;

/**
 * Derives a short display label for a contact URL — strips the
 * scheme and a trailing slash only; never rewrites, shortens, or
 * validates the URL itself. "https://github.com/danielsias" ->
 * "github.com/danielsias". Pure and deterministic.
 */
final class ResumeLinkFormatter
{
    public static function label(string $url): string
    {
        $label = preg_replace('#^https?://#i', '', $url) ?? $url;

        return rtrim($label, '/');
    }
}
