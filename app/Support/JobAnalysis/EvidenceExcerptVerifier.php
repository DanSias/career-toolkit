<?php

namespace App\Support\JobAnalysis;

use App\Exceptions\InvalidJobAnalysisResponseException;
use Normalizer;

/**
 * Deterministically checks every returned evidence excerpt against the
 * source JobPosting's description. Strict v1 policy: a single
 * unverifiable excerpt anywhere in the response invalidates the ENTIRE
 * analysis — nothing is silently filtered or dropped, and no partial
 * analysis is ever produced from a response containing even one
 * fabricated or paraphrased quote. See docs/job-analysis-generation.md.
 */
final class EvidenceExcerptVerifier
{
    /**
     * @param  array<string, mixed>  $validated  Already schema/enum-validated response.
     *
     * @throws InvalidJobAnalysisResponseException
     */
    public function verify(array $validated, string $description): void
    {
        $normalizedDescription = $this->normalize($description);

        $findings = is_array($validated['findings'] ?? null) ? $validated['findings'] : [];

        foreach ($findings as $findingIndex => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];

            foreach ($evidence as $evidenceIndex => $entry) {
                if (! is_array($entry) || ! is_string($entry['excerpt'] ?? null)) {
                    continue;
                }

                $normalizedExcerpt = $this->normalize($entry['excerpt']);

                if ($normalizedExcerpt === '' || ! str_contains($normalizedDescription, $normalizedExcerpt)) {
                    throw new InvalidJobAnalysisResponseException(
                        "Evidence excerpt for findings.{$findingIndex}.evidence.{$evidenceIndex} could not be ".
                        'verified against the source job posting description — rejecting the entire analysis.',
                        context: [
                            'finding_index' => $findingIndex,
                            'evidence_index' => $evidenceIndex,
                            'excerpt' => $entry['excerpt'],
                            'finding' => $finding,
                        ],
                    );
                }
            }
        }
    }

    /**
     * Unicode-normalizes (NFC) and collapses whitespace runs — including
     * line breaks — to a single space, then trims. Deliberately does
     * NOT fold case, replace curly quotes with straight ones, replace
     * Unicode dashes/punctuation with ASCII equivalents, or otherwise
     * transform a paraphrase into something that could pass as a quote.
     * Start conservative; widen only if the five-posting corpus later
     * demonstrates a real false negative. See
     * docs/job-analysis-generation.md.
     */
    private function normalize(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;

        $collapsed = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($collapsed);
    }
}
