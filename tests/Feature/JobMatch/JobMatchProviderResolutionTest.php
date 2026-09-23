<?php

use App\Contracts\GeneratesJobMatch;
use App\Support\JobMatch\CandidatePayloadBuilder;
use App\Support\JobMatch\GenerateJobMatch;
use App\Support\JobMatch\JobMatchResponseValidator;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\JobMatch\Prompts\JobMatchPromptV3;
use App\Support\JobMatch\Providers\OllamaJobMatchClient;
use App\Support\JobMatch\Providers\OpenAIJobMatchClient;

/**
 * Covers AppServiceProvider::resolveJobMatchProvider() — the single
 * place GeneratesJobMatch's provider is chosen, based on
 * services.job_match.provider (AI_JOB_MATCH_PROVIDER). Mirrors
 * tests/Feature/JobAnalysis/JobAnalysisProviderResolutionTest.php
 * exactly. No HTTP calls anywhere here: every case only resolves the
 * binding (which just constructs a client object) and never calls
 * ->generate().
 */
it('resolves OllamaJobMatchClient when no provider is configured at all — local-first default', function () {
    config(['services.job_match.provider' => null]);

    expect(app(GeneratesJobMatch::class))->toBeInstanceOf(OllamaJobMatchClient::class);
});

it('resolves OpenAIJobMatchClient when explicitly configured as openai', function () {
    config(['services.job_match.provider' => 'openai']);

    expect(app(GeneratesJobMatch::class))->toBeInstanceOf(OpenAIJobMatchClient::class);
});

it('resolves OllamaJobMatchClient when explicitly configured as ollama', function () {
    config([
        'services.job_match.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
        'services.ollama.job_match_model' => 'qwen-test',
        'services.ollama.job_match_timeout' => 123,
    ]);

    expect(app(GeneratesJobMatch::class))->toBeInstanceOf(OllamaJobMatchClient::class);
});

it('treats a blank provider value as the ollama default, not a failure — local-first default', function () {
    config(['services.job_match.provider' => '']);

    expect(app(GeneratesJobMatch::class))->toBeInstanceOf(OllamaJobMatchClient::class);
});

it('fails fast with a clear message for an unsupported provider value', function () {
    config(['services.job_match.provider' => 'anthropic']);

    app(GeneratesJobMatch::class);
})->throws(
    InvalidArgumentException::class,
    'Unsupported AI_JOB_MATCH_PROVIDER value [anthropic]. Supported values: openai, ollama.'
);

it('does not silently alias or normalize an unsupported provider value', function () {
    config(['services.job_match.provider' => 'OpenAI']); // wrong case is not accepted

    app(GeneratesJobMatch::class);
})->throws(InvalidArgumentException::class);

it('injects the currently configured provider into GenerateJobMatch with no change to that class', function () {
    config(['services.job_match.provider' => 'openai']);
    $openaiGenerator = app()->make(GenerateJobMatch::class);
    $openaiProvider = (new ReflectionClass($openaiGenerator))->getProperty('provider')->getValue($openaiGenerator);

    config([
        'services.job_match.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);
    $ollamaGenerator = app()->make(GenerateJobMatch::class);
    $ollamaProvider = (new ReflectionClass($ollamaGenerator))->getProperty('provider')->getValue($ollamaGenerator);

    expect($openaiProvider)->toBeInstanceOf(OpenAIJobMatchClient::class)
        ->and($ollamaProvider)->toBeInstanceOf(OllamaJobMatchClient::class);
});

it('resolves the purpose-specific Ollama model/timeout for Job Match, independently of Job Analysis\'s', function () {
    config([
        'services.job_match.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
        'services.ollama.model' => 'job-analysis-model',
        'services.ollama.timeout' => 111,
        'services.ollama.job_match_model' => 'job-match-model',
        'services.ollama.job_match_timeout' => 222,
    ]);

    $client = app(GeneratesJobMatch::class);

    expect((new ReflectionClass($client))->getProperty('model')->getValue($client))->toBe('job-match-model')
        ->and((new ReflectionClass($client))->getProperty('timeoutSeconds')->getValue($client))->toBe(222);
});

it('lets a caller construct GenerateJobMatch with an explicit OpenAIJobMatchClient regardless of AI_JOB_MATCH_PROVIDER', function () {
    // Mirrors JobAnalysisProviderResolutionTest's equivalent case —
    // proves the existing tests/Llm live-harness pattern of bypassing
    // app(GeneratesJobMatch::class) with a direct `new OpenAIJobMatchClient(...)`
    // is immune to the configurable binding, without needing a live/network test.
    config([
        'services.job_match.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);

    $generator = new GenerateJobMatch(
        provider: new OpenAIJobMatchClient(
            apiKey: (string) config('services.openai.key'),
            model: (string) config('services.openai.model'),
        ),
        prompt: app(JobMatchPromptV3::class),
        candidateBuilder: app(CandidatePayloadBuilder::class),
        jobBuilder: app(JobPayloadBuilder::class),
        validator: app(JobMatchResponseValidator::class),
    );

    $provider = (new ReflectionClass($generator))->getProperty('provider')->getValue($generator);

    expect($provider)->toBeInstanceOf(OpenAIJobMatchClient::class);
});
