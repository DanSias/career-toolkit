<?php

namespace App\Support\ResumeVariant;

use App\Enums\JobAnalysisFindingCategory;
use App\Models\JobMatch;

/**
 * Computes v1's target-term list: exactly the JobAnalysisFinding rows
 * with `category = technology` whose `statement` follows the atomic
 * "{Term} is ..." shape JobAnalysis reliably produces for a single
 * named technology (confirmed against real generations — e.g. "Azure
 * is a cloud platform relevant to...", "C# is a programming language
 * relevant to..."). A bundled finding naming several technologies at
 * once ("Have full-stack range across Python, TypeScript, SQL...")
 * never matches this shape and is intentionally left out — v1 does not
 * attempt general keyword/entity extraction from arbitrary prose. See
 * docs/domain-model.md "ResumeVariant" target-terminology scope.
 *
 * `direct_evidence_exists` is computed once here via the same
 * authorization rule ResumeSelectionResponseValidator re-checks
 * independently — see DirectEvidenceAuthorization.
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

        foreach ($findings as $finding) {
            $term = $this->extractTerm($finding->statement);

            if ($term === null) {
                continue;
            }

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

    private function extractTerm(string $statement): ?string
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
