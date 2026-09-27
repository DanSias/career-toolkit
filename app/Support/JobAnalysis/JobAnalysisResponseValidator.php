<?php

namespace App\Support\JobAnalysis;

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingBasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisMaturity;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobAnalysisSeniority;
use App\Exceptions\InvalidJobAnalysisResponseException;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The single authoritative, deterministic check on a provider's decoded
 * JobAnalysis response — structure, types, nullability, every enum
 * value, non-empty findings/evidence_refs, every evidence_refs value
 * against the exact supplied segment id set, and the
 * years_experience_min/max business rule. Runs before any Eloquent
 * model exists, so a validation failure can never be represented as "a
 * model in a bad state." Uses Laravel's own Validator rather than a
 * JSON-Schema library — this codebase already validates HTTP input
 * this way, and the same tool expresses everything
 * docs/job-analysis-contract.md requires. See
 * docs/job-analysis-generation.md.
 *
 * As of V4, this is also the sole authoritative check that a model
 * never invents a segment id — JobAnalysisPromptV4::jsonSchema()'s own
 * `enum` constraint on evidence_refs is advisory/provider-level
 * protection only (how strictly a given provider's structured-output
 * decoding actually enforces a schema enum isn't something to rely on);
 * the `Rule::in($validSegmentIds)` check below is what deterministically
 * rejects an invented/nonexistent id regardless of provider behavior.
 */
final class JobAnalysisResponseValidator
{
    /**
     * Hard ceiling on evidence_refs entries per finding — public, and
     * the single source of truth `JobAnalysisPromptV4::jsonSchema()`
     * reads directly for its own `evidence_refs.maxItems`, rather than
     * each class independently maintaining the same number. Unchanged
     * in value and purpose from the V3-era `evidence.maxItems` cap this
     * replaces — see docs/job-analysis-generation.md "Evidence
     * discipline" and "Async Job Analysis" for the full history.
     */
    public const MAX_EVIDENCE_PER_FINDING = 2;

    /**
     * @param  array<string, mixed>  $structuredContent  Untrusted, decoded provider output.
     * @param  array<int, string>  $validSegmentIds  Every segment id actually supplied to the model for this generation (from the same SegmentJobPostingDescription call used to build the prompt) — the exact set an evidence_refs value is allowed to reference.
     * @return array<string, mixed> Exactly the validated fields — nothing else.
     *
     * @throws InvalidJobAnalysisResponseException
     */
    public function validate(array $structuredContent, array $validSegmentIds): array
    {
        $validator = ValidatorFacade::make($structuredContent, $this->rules($validSegmentIds));

        $validator->after(function (Validator $validator) use ($structuredContent) {
            $this->assertValidExperienceRanges($validator, $structuredContent);
        });

        if ($validator->fails()) {
            throw new InvalidJobAnalysisResponseException(
                'JobAnalysis provider response failed validation: '
                .implode(' ', $validator->errors()->all())
            );
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * @param  array<int, string>  $validSegmentIds
     * @return array<string, mixed>
     */
    private function rules(array $validSegmentIds): array
    {
        return [
            'role_summary' => ['required', 'string'],
            'overall_seniority' => ['required', 'string', Rule::enum(JobAnalysisSeniority::class)],
            'seniority_rationale' => ['required', 'string'],

            'findings' => ['required', 'array', 'min:1'],
            'findings.*.category' => ['required', 'string', Rule::enum(JobAnalysisFindingCategory::class)],
            'findings.*.statement' => ['required', 'string'],
            'findings.*.label' => ['nullable', 'string'],
            'findings.*.basis' => ['required', 'string', Rule::enum(JobAnalysisFindingBasis::class)],
            'findings.*.requirement_strength' => ['nullable', 'string', Rule::enum(JobAnalysisRequirementStrength::class)],
            'findings.*.emphasis' => ['required', 'string', Rule::enum(JobAnalysisEmphasis::class)],
            'findings.*.maturity' => ['required', 'string', Rule::enum(JobAnalysisMaturity::class)],
            'findings.*.years_experience_min' => ['nullable', 'numeric', 'min:0'],
            'findings.*.years_experience_max' => ['nullable', 'numeric', 'min:0'],
            'findings.*.recency_requirement' => ['nullable', 'string'],
            'findings.*.time_horizon' => ['nullable', 'string'],
            'findings.*.notes' => ['nullable', 'string'],

            'findings.*.evidence_refs' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVIDENCE_PER_FINDING],
            'findings.*.evidence_refs.*' => ['required', 'string', Rule::in($validSegmentIds)],
        ];
    }

    /**
     * years_experience_max may never be less than years_experience_min
     * when both are present — the same invariant JobAnalysisFinding
     * itself enforces at the model layer, checked here too so a
     * violation fails generation outright rather than surfacing later
     * as a model-layer exception mid-transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertValidExperienceRanges(Validator $validator, array $data): void
    {
        $findings = is_array($data['findings'] ?? null) ? $data['findings'] : [];

        foreach ($findings as $index => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $min = $finding['years_experience_min'] ?? null;
            $max = $finding['years_experience_max'] ?? null;

            if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                $validator->errors()->add(
                    "findings.{$index}.years_experience_max",
                    'years_experience_max cannot be less than years_experience_min.'
                );
            }
        }
    }
}
