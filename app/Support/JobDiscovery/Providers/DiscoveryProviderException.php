<?php

namespace App\Support\JobDiscovery\Providers;

use RuntimeException;

/**
 * Thrown by a discovery provider when its own attempt fails —
 * App\Support\JobDiscovery\RunJobDiscovery catches this per-provider,
 * so one provider's failure never prevents another's success. See
 * docs/job-discovery.md "Provider failure isolation".
 */
final class DiscoveryProviderException extends RuntimeException {}
