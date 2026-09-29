// Result-shape builders for the worker's CLI/JSON boundary.
//
// The string values below are deliberately copy-pasted, not generated,
// from Career Toolkit's Phase 1 backed enums, so a future audit can diff
// this file against them directly rather than trust a codegen step:
//
//   app/Enums/ApplicationQuestionLabelSource.php
//   app/Enums/ApplicationQuestionExtractionSource.php
//   app/Enums/InspectionOutcome.php
//   app/Enums/AgentRunFailureCategory.php
//
// This worker never imports PHP or generates code from it — see
// README.md "Contract alignment" for why keeping this list hand-synced
// is the deliberate choice for a contract this small.

export const LabelSource = Object.freeze({
    DomLabelFor: 'dom_label_for',
    DomWrappingLabel: 'dom_wrapping_label',
    DomAriaLabel: 'dom_aria_label',
    DomAriaLabelledby: 'dom_aria_labelledby',
    DomPlaceholder: 'dom_placeholder',
    InferredProximity: 'inferred_proximity',
    Unresolved: 'unresolved',
});

export const ExtractionSource = Object.freeze({
    Dom: 'dom',
    Aria: 'aria',
    Both: 'both',
});

export const InspectionOutcome = Object.freeze({
    Complete: 'complete',
    Partial: 'partial',
    AuthenticationRequired: 'authentication_required',
    MutationRequired: 'mutation_required',
    Unsupported: 'unsupported',
});

// A strict SUBSET of App\Enums\AgentRunFailureCategory — the worker can
// never produce 'claim_timeout' (that value exists only for Career
// Toolkit's own staleness detection of a worker that claimed work and
// never reported back; from inside the worker process, that case is
// definitionally unobservable).
export const FailureCategory = Object.freeze({
    NavigationTimeout: 'navigation_timeout',
    UnsupportedAts: 'unsupported_ats',
    UnexpectedError: 'unexpected_error',
});

/**
 * @param {object} params
 * @param {string} params.ats
 * @param {string} params.requestedUrl
 * @param {string} params.finalUrl
 * @param {Array<object>} params.fields
 * @param {string[]} params.warnings
 * @param {object} params.diagnostics
 * @param {string} [params.inspectionOutcome]
 */
export function succeeded({
    ats,
    requestedUrl,
    finalUrl,
    fields,
    warnings,
    diagnostics,
    inspectionOutcome = InspectionOutcome.Complete,
}) {
    return {
        status: 'succeeded',
        inspection_outcome: inspectionOutcome,
        ats,
        requested_url: requestedUrl,
        final_url: finalUrl,
        fields,
        warnings,
        diagnostics,
    };
}

/**
 * A page that loaded and rendered correctly but isn't a supported ATS —
 * this is a successful technical execution, never a failure. See
 * docs/application-inspector.md "Technical status vs. inspection
 * outcome".
 *
 * @param {object} params
 * @param {string} params.requestedUrl
 * @param {string} params.finalUrl
 * @param {string[]} params.warnings
 * @param {object} params.diagnostics
 */
export function unsupported({ requestedUrl, finalUrl, warnings, diagnostics }) {
    return succeeded({
        ats: null,
        requestedUrl,
        finalUrl,
        fields: [],
        warnings,
        diagnostics,
        inspectionOutcome: InspectionOutcome.Unsupported,
    });
}

/**
 * @param {object} params
 * @param {string} params.failureCategory one of FailureCategory's values
 * @param {string} params.failureMessage
 * @param {object} params.diagnostics
 */
export function failed({ failureCategory, failureMessage, diagnostics }) {
    return {
        status: 'failed',
        failure_category: failureCategory,
        failure_message: failureMessage,
        diagnostics,
    };
}
