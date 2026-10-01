<?php

namespace App\Support\JobDiscovery;

/**
 * The smallest useful deterministic V1 discovery configuration —
 * discovery FILTERS, never fit scoring (no weights, no percentages,
 * nothing that ranks one accepted candidate above another). Built
 * once from config/job_discovery.php by
 * App\Support\JobDiscovery\RunJobDiscovery, never hand-constructed
 * inside a provider or filter class — see
 * App\Support\JobDiscovery\FilterDiscoveredCandidates.
 */
final readonly class DiscoverySearchCriteria
{
    /**
     * @param  string[]  $keywords  Whole-word/phrase OR matching against the title.
     *                              Defaults describe roles, not standalone seniority:
     *                              a CEO mentioning senior engineers is not an engineering role.
     * @param  string[]  $excludedKeywords  Reject if ANY appear in
     *                                      title+description.
     * @param  string[]  $excludedTitleKeywords  Reject if ANY appear in
     *                                           the TITLE only — role-identity
     *                                           exclusions (SRE / Site
     *                                           Reliability / primarily
     *                                           DevOps) that must never
     *                                           trigger on description
     *                                           text, which legitimately
     *                                           mentions infrastructure
     *                                           terminology in ordinary
     *                                           engineering roles. Different
     *                                           purpose from, and checked
     *                                           independently of,
     *                                           $excludedKeywords.
     * @param  string[]  $allowedLocations  Used only as a fallback
     *                                      location-text check when remoteStatus is unknown — never
     *                                      overrides an explicit Remote/Onsite/Hybrid signal.
     */
    public function __construct(
        public array $keywords,
        public array $excludedKeywords,
        public array $excludedTitleKeywords,
        public string $remotePreference,
        public array $allowedLocations,
        public ?string $employmentType,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('job_discovery.search');

        return new self(
            keywords: $config['keywords'],
            excludedKeywords: $config['excluded_keywords'],
            excludedTitleKeywords: $config['excluded_title_keywords'],
            remotePreference: $config['remote_preference'],
            allowedLocations: $config['allowed_locations'],
            employmentType: $config['employment_type'],
        );
    }
}
