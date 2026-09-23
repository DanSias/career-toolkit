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
    | Job-Analysis values; `job_match_model`/`job_match_timeout` are
    | purpose-specific so a future local-model swap for one purpose
    | never silently changes another. `qwen3.8:27b` is the current
    | validated local model for both purposes — a configuration
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
    ],

    /*
    | Which provider implementation GeneratesJobAnalysis/GeneratesJobMatch
    | resolve to — 'openai' or 'ollama'. Local-first: both default to
    | 'ollama' when unset/blank. OpenAI remains fully supported as an
    | explicitly selectable provider (a benchmark/reference path, never
    | an automatic fallback) — set the relevant AI_*_PROVIDER var to
    | 'openai' to use it. Resume Selection and Resume Wording remain
    | OpenAI-only for now (no local implementation exists yet).
    | Provider-specific settings (model, base URL, timeout, API key)
    | stay under 'openai'/'ollama' above — these keys only choose which
    | of those two configurations each purpose uses. See
    | App\Providers\AppServiceProvider::resolveJobAnalysisProvider()/
    | resolveJobMatchProvider().
    */
    'job_analysis' => [
        'provider' => env('AI_JOB_ANALYSIS_PROVIDER', 'ollama'),
    ],

    'job_match' => [
        'provider' => env('AI_JOB_MATCH_PROVIDER', 'ollama'),
    ],

];
