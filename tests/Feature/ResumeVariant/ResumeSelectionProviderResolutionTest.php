<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV3;
use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV2;
use App\Support\ResumeVariant\Providers\OllamaResumeSelectionClient;
use App\Support\ResumeVariant\Providers\OpenAIResumeSelectionClient;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\ResumeSelectionResponseValidator;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\TargetTerminologyBuilder;

/**
 * Covers AppServiceProvider::resolveResumeSelectionProvider() — the
 * single place GeneratesResumeSelection's provider is chosen, based on
 * services.resume_selection.provider (AI_RESUME_SELECTION_PROVIDER).
 * Mirrors tests/Feature/JobMatch/JobMatchProviderResolutionTest.php
 * exactly. No HTTP calls anywhere here: every case only resolves the
 * binding (which just constructs a client object) and never calls
 * ->generate().
 */
it('resolves OllamaResumeSelectionClient when no provider is configured at all — local-first default', function () {
    config(['services.resume_selection.provider' => null]);

    expect(app(GeneratesResumeSelection::class))->toBeInstanceOf(OllamaResumeSelectionClient::class);
});

it('resolves OpenAIResumeSelectionClient when explicitly configured as openai', function () {
    config(['services.resume_selection.provider' => 'openai']);

    expect(app(GeneratesResumeSelection::class))->toBeInstanceOf(OpenAIResumeSelectionClient::class);
});

it('resolves OllamaResumeSelectionClient when explicitly configured as ollama', function () {
    config([
        'services.resume_selection.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
        'services.ollama.resume_selection_model' => 'qwen-test',
        'services.ollama.resume_selection_timeout' => 123,
    ]);

    expect(app(GeneratesResumeSelection::class))->toBeInstanceOf(OllamaResumeSelectionClient::class);
});

it('treats a blank provider value as the ollama default, not a failure — local-first default', function () {
    config(['services.resume_selection.provider' => '']);

    expect(app(GeneratesResumeSelection::class))->toBeInstanceOf(OllamaResumeSelectionClient::class);
});

it('fails fast with a clear message for an unsupported provider value', function () {
    config(['services.resume_selection.provider' => 'anthropic']);

    app(GeneratesResumeSelection::class);
})->throws(
    InvalidArgumentException::class,
    'Unsupported AI_RESUME_SELECTION_PROVIDER value [anthropic]. Supported values: openai, ollama.'
);

it('does not silently alias or normalize an unsupported provider value', function () {
    config(['services.resume_selection.provider' => 'OpenAI']); // wrong case is not accepted

    app(GeneratesResumeSelection::class);
})->throws(InvalidArgumentException::class);

it('never automatically falls back to OpenAI when Ollama is selected — an unrecognized value fails, it does not silently choose openai', function () {
    config(['services.resume_selection.provider' => 'ollama-typo']);

    expect(fn () => app(GeneratesResumeSelection::class))->toThrow(InvalidArgumentException::class);
});

it('injects the currently configured provider into GenerateResumeVariant with no change to that class', function () {
    config(['services.resume_selection.provider' => 'openai']);
    $openaiGenerator = app()->make(GenerateResumeVariant::class);
    $openaiProvider = (new ReflectionClass($openaiGenerator))->getProperty('selectionProvider')->getValue($openaiGenerator);

    config([
        'services.resume_selection.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);
    $ollamaGenerator = app()->make(GenerateResumeVariant::class);
    $ollamaProvider = (new ReflectionClass($ollamaGenerator))->getProperty('selectionProvider')->getValue($ollamaGenerator);

    expect($openaiProvider)->toBeInstanceOf(OpenAIResumeSelectionClient::class)
        ->and($ollamaProvider)->toBeInstanceOf(OllamaResumeSelectionClient::class);
});

it('resolves the purpose-specific Ollama model/timeout for Resume Selection, independently of both Job Analysis\'s and Job Match\'s', function () {
    config([
        'services.resume_selection.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
        'services.ollama.model' => 'job-analysis-model',
        'services.ollama.timeout' => 111,
        'services.ollama.job_match_model' => 'job-match-model',
        'services.ollama.job_match_timeout' => 222,
        'services.ollama.resume_selection_model' => 'resume-selection-model',
        'services.ollama.resume_selection_timeout' => 333,
    ]);

    $client = app(GeneratesResumeSelection::class);

    expect((new ReflectionClass($client))->getProperty('model')->getValue($client))->toBe('resume-selection-model')
        ->and((new ReflectionClass($client))->getProperty('timeoutSeconds')->getValue($client))->toBe(333);
});

it('lets a caller construct GenerateResumeVariant with an explicit OpenAIResumeSelectionClient regardless of AI_RESUME_SELECTION_PROVIDER', function () {
    // Mirrors JobMatchProviderResolutionTest's equivalent case — proves
    // bypassing app(GeneratesResumeSelection::class) with a direct
    // `new OpenAIResumeSelectionClient(...)` is immune to the
    // configurable binding, without needing a live/network test.
    config([
        'services.resume_selection.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);

    $generator = new GenerateResumeVariant(
        selectionProvider: new OpenAIResumeSelectionClient(
            apiKey: (string) config('services.openai.key'),
            model: (string) config('services.openai.model'),
        ),
        wordingProvider: app(GeneratesResumeWording::class),
        selectionPrompt: app(ResumeSelectionPromptV3::class),
        wordingPrompt: app(ResumeWordingPromptV2::class),
        candidateBuilder: app(ResumeCandidatePayloadBuilder::class),
        jobPayloadBuilder: app(JobPayloadBuilder::class),
        targetTerminologyBuilder: app(TargetTerminologyBuilder::class),
        selectionValidator: app(ResumeSelectionResponseValidator::class),
        wordingValidator: app(ResumeWordingResponseValidator::class),
    );

    $provider = (new ReflectionClass($generator))->getProperty('selectionProvider')->getValue($generator);

    expect($provider)->toBeInstanceOf(OpenAIResumeSelectionClient::class);
});
