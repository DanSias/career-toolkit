<?php

namespace App\Support\JobAnalysis;

/**
 * Deterministically splits a JobPosting's description into small,
 * stably-ID'd, evidence-sized source segments — the foundation of
 * JobAnalysisPromptV4's evidence contract: the model cites a segment ID
 * ("S003") instead of retyping source text, and this class is the only
 * place that text is ever extracted from, so persisted evidence is
 * always exactly the real source, never a model reproduction. See
 * docs/job-analysis-generation.md "Async Job Analysis" / evidence
 * architecture.
 *
 * Deliberately small and deterministic — no fuzzy matching, no
 * semantic segmentation, no external NLP dependency. Two-level split:
 *
 * 1. The description is split into lines on `\n` — blank/whitespace-only
 *    lines are dropped, but two adjacent non-blank lines are NEVER
 *    merged into one segment, even without a blank line between them,
 *    since a source posting's bullets are typically one per line and
 *    must stay individually citable.
 * 2. Each remaining line is further split into sentences on `.`/`!`/`?`
 *    followed by whitespace and a capital letter or digit — with a
 *    small, explicit guard against splitting immediately after a
 *    handful of common abbreviations (see ABBREVIATIONS below), so
 *    "e.g. Something" doesn't become two segments.
 *
 * Every segment's text is the ORIGINAL substring, trimmed of only
 * leading/trailing whitespace — never case-folded, quote-folded,
 * punctuation-normalized, or otherwise altered. There is nothing left
 * to normalize downstream: what a segment ID resolves to is always
 * character-for-character identical to the source.
 *
 * Segmentation is pure and stateless — given the same description
 * (immutable once a JobPosting is created), it always produces the
 * same ordered segment map, so segment IDs never need to be persisted;
 * they're recomputed identically at generation time and again when
 * resolving the model's evidence_refs.
 */
final class SegmentJobPostingDescription
{
    /**
     * Sentence-final abbreviations that must not be treated as a
     * sentence boundary even though they end in one of `.`/`!`/`?`.
     * Deliberately a short, explicit list — not a general abbreviation
     * detector — matching this class's "small and deterministic"
     * mandate. Widen only if a real posting demonstrates a genuine
     * mis-split, the same evidence-driven standard already used for
     * EvidenceExcerptVerifier's quote normalization.
     *
     * @var list<string>
     */
    private const ABBREVIATIONS = [
        'e.g.', 'i.e.', 'etc.', 'vs.', 'approx.',
        'Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Jr.', 'Sr.',
        'Inc.', 'Ltd.', 'Co.', 'Corp.',
        'U.S.', 'U.K.', 'U.S.A.',
    ];

    /**
     * @return array<string, string> Ordered map of segment id ("S001",
     *                               "S002", ...) to its exact,
     *                               untouched source text.
     */
    public function segment(string $description): array
    {
        $segments = [];
        $index = 0;

        foreach (preg_split('/\R/u', $description) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            foreach ($this->splitIntoSentences($line) as $sentence) {
                $sentence = trim($sentence);

                if ($sentence === '') {
                    continue;
                }

                $index++;
                $segments[sprintf('S%03d', $index)] = $sentence;
            }
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    private function splitIntoSentences(string $line): array
    {
        $candidates = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/u', $line, -1, PREG_SPLIT_NO_EMPTY);

        if ($candidates === false || count($candidates) <= 1) {
            return [$line];
        }

        $sentences = [];
        $buffer = '';

        foreach ($candidates as $candidate) {
            $buffer = $buffer === '' ? $candidate : $buffer.' '.$candidate;

            if ($this->endsWithAbbreviation($buffer)) {
                continue;
            }

            $sentences[] = $buffer;
            $buffer = '';
        }

        if ($buffer !== '') {
            $sentences[] = $buffer;
        }

        return $sentences;
    }

    private function endsWithAbbreviation(string $text): bool
    {
        foreach (self::ABBREVIATIONS as $abbreviation) {
            if ($text === $abbreviation || str_ends_with($text, ' '.$abbreviation)) {
                return true;
            }
        }

        return false;
    }
}
