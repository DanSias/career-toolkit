<?php

namespace App\Enums;

/**
 * Which durable business process a WorkflowRun accomplishes. Exactly
 * one real case today — read-only browser-based application inspection.
 * A second workflow type is a one-line case addition, never a
 * migration. See docs/application-inspector.md.
 */
enum WorkflowType: string
{
    case ApplicationInspection = 'application_inspection';
}
