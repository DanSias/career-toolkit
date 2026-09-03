<?php

namespace App\Support\ResumeVariant;

use App\Enums\JobAnalysisFindingCategory;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;

/**
 * Computes v1's target-term list: exactly the JobAnalysisFinding rows
 * with `category = technology` that give an unambiguous, single-
 * technology signal — never general keyword/entity extraction from
 * arbitrary prose, and never a technology ontology/whitelist. See
 * docs/domain-model.md "ResumeVariant" target-terminology scope.
 *
 * Two extraction paths, in priority order:
 *
 * 1. **Structured `label`** (primary). `label` is documented (see
 *    JobAnalysisPromptV1/V2) only as "an optional short internal tag,"
 *    identical across every finding category — never guaranteed to
 *    name one technology, or to be present at all. The acceptance rule
 *    is deliberately structural, not semantic: a label is accepted
 *    only when splitting it on `_` yields exactly one token (no
 *    qualifier, no grouping) AND that token appears as a
 *    case-insensitive whole word in the finding's own `statement` —
 *    the real term is then taken from `statement` verbatim (its
 *    correct casing, e.g. "SQL," "React"), never re-cased from the
 *    label, since there is no reliable case-conversion rule for an
 *    arbitrary acronym vs. a title-cased name without a lookup table.
 *    A multi-token label (`typescript_production`, `major_cloud_provider`,
 *    `cicd_github_actions_terraform`) is always rejected outright —
 *    deliberately, even for a label that happens to name one real
 *    multi-word technology (e.g. `github_actions`, `google_cloud`):
 *    nothing short of a technology ontology could safely distinguish
 *    that case from a generic tag-plus-qualifier, and this milestone
 *    does not build one. Multi-technology labels are never
 *    delimiter-split into several terms downstream.
 * 2. **Atomic `"{Term} is ..."` statement shape** (fallback), preserved
 *    unchanged for backward compatibility with any already-persisted
 *    JobAnalysis whose technology findings happen to use that form.
 *    Tried only when the label path yields nothing.
 *
 * `direct_evidence_exists` is computed once here via the same
 * authorization rule ResumeSelectionResponseValidator re-checks
 * independently — see DirectEvidenceAuthorization. Deliberately NOT
 * gated on Daniel's Skills: a target term must be able to represent a
 * technology the candidate does not directly possess, since qualified/
 * capability posture exists specifically for adjacent or missing JD
 * technologies — DirectEvidenceAuthorization already encodes the
 * correct (stricter) rule for whether *direct* is authorized.
 */
final class TargetTerminologyBuilder
{
    private const MAX_TERM_LENGTH = 30;

    /**
     * @return array<int, array{term: string, job_analysis_finding_id: int, requirement_strength: ?string, emphasis: ?string, direct_evidence_exists: bool}>
     */
    public function build(JobMatch $jobMatch): array
    {
        $analysis = $jobMatch->jobAnalysis;

        $findings = $analysis->findings()
            ->where('category', JobAnalysisFindingCategory::Technology)
            ->orderBy('id')
            ->get();

        $entries = [];
        $seenTerms = [];

        foreach ($findings as $finding) {
            $term = $this->extractFromLabel($finding) ?? $this->extractFromStatement($finding->statement);

            if ($term === null) {
                continue;
            }

            $key = mb_strtolower($term);

            // The same real technology could in principle be extracted
            // from two different findings (one via label, one via the
            // statement fallback) — keep only the first, deterministic
            // by finding id order, rather than emitting a duplicate
            // target term.
            if (isset($seenTerms[$key])) {
                continue;
            }
            $seenTerms[$key] = true;

            $entries[] = [
                'term' => $term,
                'job_analysis_finding_id' => $finding->id,
                'requirement_strength' => $finding->requirement_strength?->value,
                'emphasis' => $finding->emphasis?->value,
                'direct_evidence_exists' => DirectEvidenceAuthorization::forFinding($jobMatch, $finding, $term),
            ];
        }

        return $entries;
    }

    /**
     * Structural-only acceptance: exactly one underscore-delimited
     * token, confirmed present verbatim (case-insensitive whole word)
     * in the finding's own statement. Never strips a qualifier word,
     * never re-cases the label itself, never consults a list of known
     * technology names.
     */
    private function extractFromLabel(JobAnalysisFinding $finding): ?string
    {
        $label = $finding->label;

        if ($label === null || $label === '') {
            return null;
        }

        if (str_contains($label, '_')) {
            return null;
        }

        if (preg_match('/\b'.preg_quote($label, '/').'\b/i', $finding->statement, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    private function extractFromStatement(string $statement): ?string
    {
        $position = strpos($statement, ' is ');

        if ($position === false) {
            return null;
        }

        $term = trim(substr($statement, 0, $position));

        if ($term === '' || strlen($term) > self::MAX_TERM_LENGTH || str_contains($term, ',')) {
            return null;
        }

        return $term;
    }
}
