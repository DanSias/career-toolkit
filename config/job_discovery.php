<?php

return [

    /*
    | The V1 deterministic discovery criteria — see App\Support\
    | JobDiscovery\DiscoverySearchCriteria::fromConfig() and
    | App\Support\JobDiscovery\FilterDiscoveredCandidates. A plain
    | config file, not a database-backed settings table: this is a
    | single-user, single-search-profile system with no settings UI
    | anywhere in the app today, so a config file is the smallest
    | maintainable representation — see docs/job-discovery.md "Search
    | configuration location". Edit this file (not adapter code) to
    | change what discovery looks for.
    |
    | DISCOVERY filters only — never fit scoring. `keywords` includes
    | both role/technology terms and seniority terms in one OR-matched
    | list (see DiscoverySearchCriteria's own docblock for why), tuned
    | to this user's actual target profile: Senior/Staff full-stack,
    | backend/API, data-heavy, developer tooling, and AI-enabled
    | product engineering, remote US.
    */
    'search' => [
        'keywords' => [
            'senior', 'staff',
            'software engineer', 'full stack', 'full-stack', 'backend',
            'back-end', 'back end', 'api engineer', 'platform engineer',
            'developer tools', 'developer experience', 'data engineer',
            'data pipeline', 'analytics engineer',
        ],

        'excluded_keywords' => [
            'intern', 'internship', 'entry level', 'entry-level',
            'junior', 'jr.', 'new grad', 'new graduate',
        ],

        // 'remote_us' or 'any' — see FilterDiscoveredCandidates.
        'remote_preference' => 'remote_us',

        // Fallback location-text matching only, used when a
        // candidate's remote_status is unknown.
        'allowed_locations' => ['United States', 'US', 'USA', 'Remote'],

        // Null = don't filter by employment type. A candidate's own
        // employment_type is only ever checked when both this AND the
        // candidate's own value are non-null.
        'employment_type' => null,
    ],

];
