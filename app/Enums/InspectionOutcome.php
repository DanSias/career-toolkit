<?php

namespace App\Enums;

/**
 * What an AgentRun's inspection actually achieved — populated only when
 * AgentRun.status is Succeeded, and deliberately orthogonal to that
 * status: a technically successful execution that correctly stopped at
 * an authentication wall it was never authorized to cross is
 * status: Succeeded, inspection_outcome: AuthenticationRequired, never
 * a failure. Greenhouse-only v1 will normally only ever produce
 * Complete; the other cases exist now so this schema doesn't need to
 * change the day a second ATS is supported. See
 * docs/domain-model.md "WorkflowRun, WorkflowStep, and AgentRun".
 */
enum InspectionOutcome: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case AuthenticationRequired = 'authentication_required';
    case MutationRequired = 'mutation_required';
    case Unsupported = 'unsupported';
}
