<?php

namespace App\Enums;

/**
 * The kind of claim a CareerFact represents.
 *
 * Stored as a plain string column (not a database ENUM) so new cases can be
 * added here without a migration.
 */
enum CareerFactType: string
{
    case Bullet = 'bullet';
    case Metric = 'metric';
    case Capability = 'capability';
    case Narrative = 'narrative';
}
