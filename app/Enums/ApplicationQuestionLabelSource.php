<?php

namespace App\Enums;

/**
 * How an ApplicationQuestion's raw_label was resolved, in the priority
 * order a real extraction attempts them — mirrors the disposable
 * browser proof of concept's own resolveLabel() implementation,
 * verified against real Greenhouse and Lever application forms.
 * Unresolved is a legitimate, honest outcome, not an error — see
 * docs/application-inspector.md "ApplicationQuestion ordering".
 */
enum ApplicationQuestionLabelSource: string
{
    case DomLabelFor = 'dom_label_for';
    case DomWrappingLabel = 'dom_wrapping_label';
    case DomAriaLabel = 'dom_aria_label';
    case DomAriaLabelledby = 'dom_aria_labelledby';
    case DomPlaceholder = 'dom_placeholder';
    case InferredProximity = 'inferred_proximity';
    case Unresolved = 'unresolved';
}
