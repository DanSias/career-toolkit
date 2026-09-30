<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.6'),
    ],

    /*
    | Ollama (OpenAI-compatible Chat Completions endpoint on a
    | self-hosted server) — the local-first default provider. No API
    | key — Ollama's OpenAI-compat layer doesn't require one. `base_url`
    | is intentionally left with no default: it must be explicitly
    | configured per environment (never a LAN IP baked into application
    | code), and every consumer treats a blank value as "Ollama is not
    | configured here." `model`/`timeout` below are the generic/
    | Job-Analysis values; every other `*_model`/`*_timeout` pair is
    | purpose-specific so a future local-model swap for one purpose
    | never silently changes another. `qwen3.8:27b` is the current
    | validated local model for all purposes — a configuration
    | default, not an architectural dependency; nothing in the
    | Ollama*Client classes or the prompts they call assumes this
    | specific model. See app/Support/OllamaChatCompletionsClient.php.
    */
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL'),
        'model' => env('OLLAMA_MODEL', 'qwen3.8:27b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT_SECONDS', 300),
        'job_match_model' => env('OLLAMA_JOB_MATCH_MODEL', 'qwen3.8:27b'),
        'job_match_timeout' => (int) env('OLLAMA_JOB_MATCH_TIMEOUT_SECONDS', 600),
        'resume_selection_model' => env('OLLAMA_RESUME_SELECTION_MODEL', 'qwen3.8:27b'),
        'resume_selection_timeout' => (int) env('OLLAMA_RESUME_SELECTION_TIMEOUT_SECONDS', 900),
        'resume_wording_model' => env('OLLAMA_RESUME_WORDING_MODEL', 'qwen3.8:27b'),
        'resume_wording_timeout' => (int) env('OLLAMA_RESUME_WORDING_TIMEOUT_SECONDS', 900),
    ],

    /*
    | Which provider implementation GeneratesJobAnalysis/GeneratesJobMatch/
    | GeneratesResumeSelection/GeneratesResumeWording resolve to —
    | 'openai' or 'ollama'. Local-first: all four default to 'ollama'
    | when unset/blank.
    | OpenAI remains fully supported as an explicitly selectable provider
    | (a benchmark/reference path, never an automatic fallback) — set the
    | relevant AI_*_PROVIDER var to 'openai' to use it.
    | Provider-specific settings (model, base URL, timeout, API key)
    | stay under 'openai'/'ollama' above — these keys only choose which
    | of those two configurations each purpose uses. See
    | App\Providers\AppServiceProvider::resolveJobAnalysisProvider()/
    | resolveJobMatchProvider()/resolveResumeSelectionProvider()/
    | resolveResumeWordingProvider().
    */
    'job_analysis' => [
        'provider' => env('AI_JOB_ANALYSIS_PROVIDER', 'ollama'),
    ],

    'job_match' => [
        'provider' => env('AI_JOB_MATCH_PROVIDER', 'ollama'),
    ],

    'resume_selection' => [
        'provider' => env('AI_RESUME_SELECTION_PROVIDER', 'ollama'),
    ],

    'resume_wording' => [
        'provider' => env('AI_RESUME_WORDING_PROVIDER', 'ollama'),
    ],

    /*
    | Shared-secret Bearer token guarding routes/api.php's worker
    | protocol endpoints (App\Http\Middleware\AuthenticateBrowserWorker)
    | — never Sanctum; see docs/application-inspector.md "Approved v1
    | worker authentication". claim_ttl is how long a claimed AgentRun
    | may run before Career Toolkit treats the worker as gone and
    | recovers the claim as failed (failure_category: claim_timeout) —
    | see App\Support\ApplicationInspection\ClaimNextAgentRun. Default
    | (60s) is sized against the real AI-box smoke-test timing (~3.5s
    | per Greenhouse inspection), not the earlier, more conservative
    | 120s estimate from before that data existed.
    */
    'browser_worker' => [
        'token' => env('BROWSER_WORKER_TOKEN'),
        'claim_ttl' => (int) env('BROWSER_WORKER_CLAIM_TTL', 60),

        // The worker's own expected poll cadence
        // (BROWSER_WORKER_POLL_INTERVAL_MS in workers/browser-inspector,
        // in seconds here) — Career Toolkit's side of the same number,
        // kept as a plain default rather than something the worker
        // reports, since one shared expectation is simpler than a
        // negotiated one for a single-worker system. Used only to
        // derive App\Support\ApplicationInspection\
        // PresentWorkerAvailability's online/offline threshold — never
        // read by the UI directly. See docs/application-inspector.md
        // "Worker presence".
        'poll_interval_seconds' => (int) env('BROWSER_WORKER_POLL_INTERVAL_SECONDS', 5),
    ],

    /*
    | Broad-discovery providers (App\Support\JobDiscovery\Providers) —
    | see docs/job-discovery.md. Himalayas' public feed
    | (himalayas.app/jobs/api) needs no credentials at all. Adzuna
    | requires a free app_id/app_key pair (developer.adzuna.com) — a
    | missing key fails that one provider's attempt closed (see
    | App\Support\JobDiscovery\Providers\AdzunaDiscoveryProvider),
    | never silently skipped and never blocking Himalayas.
    */
    'himalayas' => [
        'base_url' => env('HIMALAYAS_BASE_URL', 'https://himalayas.app/jobs/api'),
    ],

    'adzuna' => [
        'app_id' => env('ADZUNA_APP_ID'),
        'app_key' => env('ADZUNA_APP_KEY'),
        'country' => env('ADZUNA_COUNTRY', 'us'),
    ],

];
