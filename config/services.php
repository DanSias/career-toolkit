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
    | self-hosted server). No API key — Ollama's OpenAI-compat layer
    | doesn't require one. `base_url` is intentionally left with no
    | default: it must be explicitly configured per environment (never
    | a LAN IP baked into application code), and every consumer treats
    | a blank value as "Ollama is not configured here." `timeout`
    | defaults well above OpenAI's because local inference on
    | consumer-grade hardware runs meaningfully slower than a hosted
    | API. See app/Support/OllamaChatCompletionsClient.php.
    */
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL'),
        'model' => env('OLLAMA_MODEL', 'qwen3.8:27b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT_SECONDS', 300),
    ],

    /*
    | Which provider implementation GeneratesJobAnalysis resolves to —
    | 'openai' or 'ollama'. Job Analysis is the only purpose that's
    | provider-selectable today; Job Match, Resume Selection, and Resume
    | Wording remain OpenAI-only. Provider-specific settings (model,
    | base URL, timeout, API key) stay under 'openai'/'ollama' above —
    | this key only chooses which of those two configurations Job
    | Analysis uses. See
    | App\Providers\AppServiceProvider::resolveJobAnalysisProvider().
    */
    'job_analysis' => [
        'provider' => env('AI_JOB_ANALYSIS_PROVIDER', 'openai'),
    ],

];
