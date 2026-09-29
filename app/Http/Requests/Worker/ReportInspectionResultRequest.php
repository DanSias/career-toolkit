<?php

namespace App\Http\Requests\Worker;

use App\Enums\AgentRunFailureCategory;
use App\Enums\ApplicationQuestionExtractionSource;
use App\Enums\ApplicationQuestionLabelSource;
use App\Enums\InspectionOutcome;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the worker's exact Phase 2 InspectionResult contract (see
 * workers/browser-inspector/src/result.js and README.md "JSON output
 * contract") before it ever reaches
 * App\Support\ApplicationInspection\PersistInspectionResult — the
 * fail-closed trust boundary between an external process's output and
 * this application's durable state, the same posture the JobAnalysis/
 * JobMatch/ResumeVariant pipelines already establish for LLM output.
 *
 * authorize() is unconditionally true: App\Http\Middleware\
 * AuthenticateBrowserWorker already gates every route this request is
 * used on.
 */
class ReportInspectionResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['succeeded', 'failed'])],

            'inspection_outcome' => ['required_if:status,succeeded', Rule::enum(InspectionOutcome::class)],
            'ats' => ['nullable', 'string', 'max:100'],
            'requested_url' => ['nullable', 'string', 'max:2048'],
            'final_url' => ['nullable', 'string', 'max:2048'],

            // present_if, not required_if: an unsupported-ATS result
            // legitimately sends fields: [] (a real, present, empty
            // array) — required_if's underlying `required` rule treats
            // an empty array as absent, exactly the same present+array
            // (not required+array) distinction JobMatchResponseValidator
            // already establishes for matches/education_matches.
            'fields' => ['present_if:status,succeeded', 'array'],
            'fields.*.position' => ['required', 'integer', 'min:0'],
            'fields.*.external_field_id' => ['nullable', 'string', 'max:255'],
            'fields.*.raw_label' => ['nullable', 'string'],
            'fields.*.label_source' => ['required', Rule::enum(ApplicationQuestionLabelSource::class)],
            'fields.*.control_type' => ['required', 'string', 'max:100'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*' => ['string'],
            'fields.*.section' => ['nullable', 'string', 'max:255'],
            'fields.*.extraction_source' => ['required', Rule::enum(ApplicationQuestionExtractionSource::class)],

            // The worker can never legitimately report claim_timeout —
            // that value exists only for Career Toolkit's own
            // staleness detection (see App\Support\ApplicationInspection\
            // ClaimNextAgentRun). Excluding it here means a
            // worker/attacker attempting to report it fails validation
            // outright, rather than silently being accepted.
            'failure_category' => [
                'required_if:status,failed',
                Rule::in([
                    AgentRunFailureCategory::NavigationTimeout->value,
                    AgentRunFailureCategory::UnsupportedAts->value,
                    AgentRunFailureCategory::UnexpectedError->value,
                ]),
            ],
            'failure_message' => ['required_if:status,failed', 'string'],

            'warnings' => ['nullable', 'array'],
            'warnings.*' => ['string'],
            'diagnostics' => ['nullable', 'array'],
        ];
    }
}
