<?php

namespace App\Enums;

/**
 * How a Metric's value relates to reality, when that isn't a plain exact
 * figure (e.g. "$25M+" is AtLeast, "~20 hours" is Approximately).
 */
enum MetricComparator: string
{
    case Exact = 'exact';
    case AtLeast = 'at_least';
    case Approximately = 'approximately';
}
