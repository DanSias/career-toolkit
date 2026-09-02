<?php

namespace App\Enums;

/**
 * Where a piece of Evidence came from.
 *
 * This list has already grown once during reconciliation (portfolio ->
 * +repository) and should be expected to grow again. Stored as a plain
 * string column so adding a case here never requires a migration.
 */
enum EvidenceSource: string
{
    case Resume = 'resume';
    case Portfolio = 'portfolio';
    case Repository = 'repository';
    case UserConfirmed = 'user_confirmed';
}
