<?php

namespace App\Support\JobDiscovery;

use RuntimeException;

/** A stable identity conflict: preserve existing rows and report the skipped candidate. */
final class DiscoveryIngestionException extends RuntimeException {}
