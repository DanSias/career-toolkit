<?php

namespace App\Support\JobMatch;

use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use App\Exceptions\InvalidJobMatchResponseException;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The single authoritative, deterministic check on a provider's decoded
 * JobMatch response — structure, types, every enum value, completeness
 * (every supplied finding addressed exactly once), referential
 * integrity (every referenced finding/fact/education id was actually
 * supplied), no duplicate references, and no impossible coverage state.
 * Runs before any Eloquent model exists, so a validation failure can
 * never be represented as "a model in a bad state." Mirrors
 * App\Support\JobAnalysis\JobAnalysisResponseValidator. See
 * docs/job-match-generation.md.
 */
final class JobMatchResponseValidator
{
    /**
     * @param  array<string, mixed>  $structuredContent  Untrusted, decoded provider output.
     * @param  array<int, int>  $validFindingIds  Every JobAnalysisFinding id supplied to the provider.
     * @param  array<int, string>  $validFactKeys  Every CareerFact key supplied to the provider.
     * @param  array<int, int>  $validEducationIds  Every Education id supplied to the provider.
     * @return array<string, mixed> Exactly the validated fields — nothing else.
     *
     * @throws InvalidJobMatchResponseException
     */
    public function validate(
        array $structuredContent,
        array $validFindingIds,
        array $validFactKeys,
        array $validEducationIds,
    ): array {
        $validator = ValidatorFacade::make($structuredContent, $this->rules());

        $validator->after(function (Validator $validator) use ($structuredContent, $validFindingIds, $validFactKeys, $validEducationIds) {
            $findings = is_array($structuredContent['findings'] ?? null) ? $structuredContent['findings'] : [];

            $this->assertCompleteness($validator, $findings, $validFindingIds);
            $this->assertReferentialIntegrity($validator, $findings, $validFactKeys, $validEducationIds);
            $this->assertNoDuplicateReferences($validator, $findings);
            $this->assertPossibleCoverageStates($validator, $findings);
        });

        if ($validator->fails()) {
            throw new InvalidJobMatchResponseException(
                'JobMatch provider response failed validation: '
                .implode(' ', $validator->errors()->all())
            );
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'findings' => ['required', 'array', 'min:1'],
            'findings.*.job_analysis_finding_id' => ['required', 'integer'],
            'findings.*.coverage' => ['required', 'string', Rule::enum(JobMatchCoverage::class)],
            'findings.*.coverage_rationale' => ['nullable', 'string'],

            // `present` rather than `required`: Laravel's `required`
            // treats an empty array as absent, but an empty array here
            // is a completely valid, expected value (e.g. every
            // no_evidence/not_assessable finding must have one) — the
            // rule only needs to confirm the key exists at all.
            'findings.*.matches' => ['present', 'array'],
            'findings.*.matches.*.career_fact_key' => ['required', 'string'],
            'findings.*.matches.*.relationship' => ['required', 'string', Rule::enum(MatchRelationship::class)],
            'findings.*.matches.*.rationale' => ['nullable', 'string'],

            'findings.*.education_matches' => ['present', 'array'],
            'findings.*.education_matches.*.education_id' => ['required', 'integer'],
            'findings.*.education_matches.*.relationship' => ['required', 'string', Rule::enum(MatchRelationship::class)],
            'findings.*.education_matches.*.rationale' => ['nullable', 'string'],
        ];
    }

    /**
     * Every JobAnalysisFinding supplied to the provider must appear in
     * the response exactly once — no missing findings, no duplicates.
     * This is what makes a `no_evidence`/`not_assessable` result
     * trustworthy as "the model considered this," not "the model
     * forgot it."
     *
     * @param  array<int, mixed>  $findings
     * @param  array<int, int>  $validFindingIds
     */
    private function assertCompleteness(Validator $validator, array $findings, array $validFindingIds): void
    {
        $seenIds = [];

        foreach ($findings as $index => $finding) {
            if (! is_array($finding) || ! isset($finding['job_analysis_finding_id'])) {
                continue;
            }

            $id = $finding['job_analysis_finding_id'];

            if (in_array($id, $seenIds, true)) {
                $validator->errors()->add(
                    "findings.{$index}.job_analysis_finding_id",
                    "Finding id [{$id}] appears more than once in the response."
                );

                continue;
            }

            $seenIds[] = $id;
        }

        $missing = array_diff($validFindingIds, $seenIds);

        if ($missing !== []) {
            $validator->errors()->add(
                'findings',
                'Response is missing finding id(s): '.implode(', ', $missing).'.'
            );
        }

        $unexpected = array_diff($seenIds, $validFindingIds);

        if ($unexpected !== []) {
            $validator->errors()->add(
                'findings',
                'Response references finding id(s) not supplied to the provider: '.implode(', ', $unexpected).'.'
            );
        }
    }

    /**
     * Every referenced career_fact_key/education_id must be one of the
     * exact values actually supplied in this run's provider input —
     * re-checked independently of the JSON Schema's own enum
     * constraint, never trusting the provider-side constraint alone.
     *
     * @param  array<int, mixed>  $findings
     * @param  array<int, string>  $validFactKeys
     * @param  array<int, int>  $validEducationIds
     */
    private function assertReferentialIntegrity(Validator $validator, array $findings, array $validFactKeys, array $validEducationIds): void
    {
        foreach ($findings as $findingIndex => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            foreach ((is_array($finding['matches'] ?? null) ? $finding['matches'] : []) as $matchIndex => $match) {
                $key = is_array($match) ? ($match['career_fact_key'] ?? null) : null;

                if (is_string($key) && ! in_array($key, $validFactKeys, true)) {
                    $validator->errors()->add(
                        "findings.{$findingIndex}.matches.{$matchIndex}.career_fact_key",
                        "career_fact_key [{$key}] was not supplied in the provider input."
                    );
                }
            }

            foreach ((is_array($finding['education_matches'] ?? null) ? $finding['education_matches'] : []) as $matchIndex => $match) {
                $id = is_array($match) ? ($match['education_id'] ?? null) : null;

                if (is_int($id) && ! in_array($id, $validEducationIds, true)) {
                    $validator->errors()->add(
                        "findings.{$findingIndex}.education_matches.{$matchIndex}.education_id",
                        "education_id [{$id}] was not supplied in the provider input."
                    );
                }
            }
        }
    }

    /**
     * No duplicate CareerFact/Education reference within a single
     * finding's match lists.
     *
     * @param  array<int, mixed>  $findings
     */
    private function assertNoDuplicateReferences(Validator $validator, array $findings): void
    {
        foreach ($findings as $findingIndex => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $factKeys = [];
            foreach ((is_array($finding['matches'] ?? null) ? $finding['matches'] : []) as $matchIndex => $match) {
                $key = is_array($match) ? ($match['career_fact_key'] ?? null) : null;

                if (is_string($key)) {
                    if (in_array($key, $factKeys, true)) {
                        $validator->errors()->add(
                            "findings.{$findingIndex}.matches.{$matchIndex}.career_fact_key",
                            "career_fact_key [{$key}] is referenced more than once within the same finding."
                        );
                    }
                    $factKeys[] = $key;
                }
            }

            $educationIds = [];
            foreach ((is_array($finding['education_matches'] ?? null) ? $finding['education_matches'] : []) as $matchIndex => $match) {
                $id = is_array($match) ? ($match['education_id'] ?? null) : null;

                if (is_int($id)) {
                    if (in_array($id, $educationIds, true)) {
                        $validator->errors()->add(
                            "findings.{$findingIndex}.education_matches.{$matchIndex}.education_id",
                            "education_id [{$id}] is referenced more than once within the same finding."
                        );
                    }
                    $educationIds[] = $id;
                }
            }
        }
    }

    /**
     * `no_evidence` and `not_assessable` require zero support
     * references (citing evidence would contradict either
     * classification); `supported` and `partial` require at least one,
     * across CareerFact and Education references combined. Rejected
     * outright, same all-or-nothing policy as everywhere else in this
     * pipeline — no silent coercion of an impossible state into a
     * plausible-sounding one.
     *
     * @param  array<int, mixed>  $findings
     */
    private function assertPossibleCoverageStates(Validator $validator, array $findings): void
    {
        foreach ($findings as $index => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $coverage = $finding['coverage'] ?? null;
            $matchCount = is_array($finding['matches'] ?? null) ? count($finding['matches']) : 0;
            $educationMatchCount = is_array($finding['education_matches'] ?? null) ? count($finding['education_matches']) : 0;
            $totalSupport = $matchCount + $educationMatchCount;

            $noSupportExpected = in_array($coverage, [JobMatchCoverage::NoEvidence->value, JobMatchCoverage::NotAssessable->value], true);
            $someSupportExpected = in_array($coverage, [JobMatchCoverage::Supported->value, JobMatchCoverage::Partial->value], true);

            if ($noSupportExpected && $totalSupport > 0) {
                $validator->errors()->add(
                    "findings.{$index}.coverage",
                    "coverage [{$coverage}] requires zero support references, but {$totalSupport} were supplied."
                );
            }

            if ($someSupportExpected && $totalSupport === 0) {
                $validator->errors()->add(
                    "findings.{$index}.coverage",
                    "coverage [{$coverage}] requires at least one support reference, but none were supplied."
                );
            }
        }
    }
}
