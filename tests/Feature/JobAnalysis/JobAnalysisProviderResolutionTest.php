<?php

use App\Contracts\GeneratesJobAnalysis;
use App\Support\JobAnalysis\EvidenceExcerptVerifier;
use App\Support\JobAnalysis\GenerateJobAnalysis;
use App\Support\JobAnalysis\JobAnalysisResponseValidator;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV2;
use App\Support\JobAnalysis\Providers\OllamaJobAnalysisClient;
use App\Support\JobAnalysis\Providers\OpenAIJobAnalysisClient;

/**
 * Covers AppServiceProvider::resolveJobAnalysisProvider() — the single
 * place GeneratesJobAnalysis's provider is chosen based on
 * services.job_analysis.provider (AI_JOB_ANALYSIS_PROVIDER). No HTTP
 * calls anywhere here: every case only resolves the binding (which
 * just constructs a client object) and never calls ->generate().
 */
it('resolves OllamaJobAnalysisClient when no provider is configured at all — local-first default', function () {
    config(['services.job_analysis.provider' => null]);

    expect(app(GeneratesJobAnalysis::class))->toBeInstanceOf(OllamaJobAnalysisClient::class);
});

it('resolves OpenAIJobAnalysisClient when explicitly configured as openai', function () {
    config(['services.job_analysis.provider' => 'openai']);

    expect(app(GeneratesJobAnalysis::class))->toBeInstanceOf(OpenAIJobAnalysisClient::class);
});

it('resolves OllamaJobAnalysisClient when explicitly configured as ollama', function () {
    config([
        'services.job_analysis.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
        'services.ollama.model' => 'qwen-test',
        'services.ollama.timeout' => 123,
    ]);

    expect(app(GeneratesJobAnalysis::class))->toBeInstanceOf(OllamaJobAnalysisClient::class);
});

it('treats a blank provider value as the ollama default, not a failure — local-first default', function () {
    config(['services.job_analysis.provider' => '']);

    expect(app(GeneratesJobAnalysis::class))->toBeInstanceOf(OllamaJobAnalysisClient::class);
});

it('fails fast with a clear message for an unsupported provider value', function () {
    config(['services.job_analysis.provider' => 'anthropic']);

    app(GeneratesJobAnalysis::class);
})->throws(
    InvalidArgumentException::class,
    'Unsupported AI_JOB_ANALYSIS_PROVIDER value [anthropic]. Supported values: openai, ollama.'
);

it('does not silently alias or normalize an unsupported provider value', function () {
    config(['services.job_analysis.provider' => 'OpenAI']); // wrong case is not accepted

    app(GeneratesJobAnalysis::class);
})->throws(InvalidArgumentException::class);

it('injects the currently configured provider into GenerateJobAnalysis with no change to that class', function () {
    config(['services.job_analysis.provider' => 'openai']);
    $openaiGenerator = app()->make(GenerateJobAnalysis::class);
    $openaiProvider = (new ReflectionClass($openaiGenerator))->getProperty('provider')->getValue($openaiGenerator);

    config([
        'services.job_analysis.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);
    $ollamaGenerator = app()->make(GenerateJobAnalysis::class);
    $ollamaProvider = (new ReflectionClass($ollamaGenerator))->getProperty('provider')->getValue($ollamaGenerator);

    expect($openaiProvider)->toBeInstanceOf(OpenAIJobAnalysisClient::class)
        ->and($ollamaProvider)->toBeInstanceOf(OllamaJobAnalysisClient::class);
});

it('lets a caller construct GenerateJobAnalysis with an explicit OpenAIJobAnalysisClient regardless of AI_JOB_ANALYSIS_PROVIDER', function () {
    // Mirrors exactly how tests/Llm/JobAnalysisLiveCorpusTest.php's
    // jobAnalysisViaOpenAI() and tests/Llm/OpenAIJobAnalysisLiveTest.php
    // construct their provider — bypassing app(GeneratesJobAnalysis::class)
    // entirely with a direct `new OpenAIJobAnalysisClient(...)` — to prove
    // that pattern is immune to the configurable binding, without needing
    // an actual live/network test. Set to 'ollama' specifically because
    // it's the one value that would resolve something other than
    // OpenAIJobAnalysisClient through the container binding.
    config([
        'services.job_analysis.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);

    $generator = new GenerateJobAnalysis(
        provider: new OpenAIJobAnalysisClient(
            apiKey: (string) config('services.openai.key'),
            model: (string) config('services.openai.model'),
        ),
        prompt: app(JobAnalysisPromptV2::class),
        validator: app(JobAnalysisResponseValidator::class),
        evidenceVerifier: app(EvidenceExcerptVerifier::class),
    );

    $provider = (new ReflectionClass($generator))->getProperty('provider')->getValue($generator);

    expect($provider)->toBeInstanceOf(OpenAIJobAnalysisClient::class);
});
