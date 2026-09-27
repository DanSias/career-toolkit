<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV3;
use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV2;
use App\Support\ResumeVariant\Providers\OllamaResumeWordingClient;
use App\Support\ResumeVariant\Providers\OpenAIResumeWordingClient;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\ResumeSelectionResponseValidator;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\TargetTerminologyBuilder;

/**
 * Covers AppServiceProvider::resolveResumeWordingProvider() — the
 * single place GeneratesResumeWording's provider is chosen, based on
 * services.resume_wording.provider (AI_RESUME_WORDING_PROVIDER).
 * Mirrors tests/Feature/ResumeVariant/ResumeSelectionProviderResolutionTest.php
 * exactly. No HTTP calls anywhere here: every case only resolves the
 * binding (which just constructs a client object) and never calls
 * ->generate().
 */
it('resolves OllamaResumeWordingClient when no provider is configured at all — local-first default', function () {
    config(['services.resume_wording.provider' => null]);

    expect(app(GeneratesResumeWording::class))->toBeInstanceOf(OllamaResumeWordingClient::class);
});

it('resolves OpenAIResumeWordingClient when explicitly configured as openai', function () {
    config(['services.resume_wording.provider' => 'openai']);

    expect(app(GeneratesResumeWording::class))->toBeInstanceOf(OpenAIResumeWordingClient::class);
});

it('resolves OllamaResumeWordingClient when explicitly configured as ollama', function () {
    config([
        'services.resume_wording.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
        'services.ollama.resume_wording_model' => 'qwen-test',
        'services.ollama.resume_wording_timeout' => 123,
    ]);

    expect(app(GeneratesResumeWording::class))->toBeInstanceOf(OllamaResumeWordingClient::class);
});

it('treats a blank provider value as the ollama default, not a failure — local-first default', function () {
    config(['services.resume_wording.provider' => '']);

    expect(app(GeneratesResumeWording::class))->toBeInstanceOf(OllamaResumeWordingClient::class);
});

it('fails fast with a clear message for an unsupported provider value', function () {
    config(['services.resume_wording.provider' => 'anthropic']);

    app(GeneratesResumeWording::class);
})->throws(
    InvalidArgumentException::class,
    'Unsupported AI_RESUME_WORDING_PROVIDER value [anthropic]. Supported values: openai, ollama.'
);

it('does not silently alias or normalize an unsupported provider value', function () {
    config(['services.resume_wording.provider' => 'OpenAI']); // wrong case is not accepted

    app(GeneratesResumeWording::class);
})->throws(InvalidArgumentException::class);

it('never automatically falls back to OpenAI when Ollama is selected — an unrecognized value fails, it does not silently choose openai', function () {
    config(['services.resume_wording.provider' => 'ollama-typo']);

    expect(fn () => app(GeneratesResumeWording::class))->toThrow(InvalidArgumentException::class);
});

it('still resolves Ollama rather than OpenAI when Ollama is selected but no base URL is configured — failure is deferred to the call, never silently rerouted', function () {
    config([
        'services.resume_wording.provider' => 'ollama',
        'services.ollama.base_url' => null,
    ]);

    expect(app(GeneratesResumeWording::class))->toBeInstanceOf(OllamaResumeWordingClient::class);
});

it('injects the currently configured provider into GenerateResumeVariant with no change to that class', function () {
    config(['services.resume_wording.provider' => 'openai']);
    $openaiGenerator = app()->make(GenerateResumeVariant::class);
    $openaiProvider = (new ReflectionClass($openaiGenerator))->getProperty('wordingProvider')->getValue($openaiGenerator);

    config([
        'services.resume_wording.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);
    $ollamaGenerator = app()->make(GenerateResumeVariant::class);
    $ollamaProvider = (new ReflectionClass($ollamaGenerator))->getProperty('wordingProvider')->getValue($ollamaGenerator);

    expect($openaiProvider)->toBeInstanceOf(OpenAIResumeWordingClient::class)
        ->and($ollamaProvider)->toBeInstanceOf(OllamaResumeWordingClient::class);
});

it('resolves the purpose-specific Ollama model/timeout for Resume Wording, independently of Job Analysis\'s, Job Match\'s, and Resume Selection\'s', function () {
    config([
        'services.resume_wording.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
        'services.ollama.model' => 'job-analysis-model',
        'services.ollama.timeout' => 111,
        'services.ollama.job_match_model' => 'job-match-model',
        'services.ollama.job_match_timeout' => 222,
        'services.ollama.resume_selection_model' => 'resume-selection-model',
        'services.ollama.resume_selection_timeout' => 333,
        'services.ollama.resume_wording_model' => 'resume-wording-model',
        'services.ollama.resume_wording_timeout' => 444,
    ]);

    $client = app(GeneratesResumeWording::class);

    expect((new ReflectionClass($client))->getProperty('model')->getValue($client))->toBe('resume-wording-model')
        ->and((new ReflectionClass($client))->getProperty('timeoutSeconds')->getValue($client))->toBe(444);
});

it('chooses each stage\'s provider independently — Selection on Ollama with Wording on OpenAI is a legal combination', function () {
    config([
        'services.resume_selection.provider' => 'ollama',
        'services.resume_wording.provider' => 'openai',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);

    expect(app(GeneratesResumeSelection::class))->not->toBeInstanceOf(OllamaResumeWordingClient::class)
        ->and(app(GeneratesResumeWording::class))->toBeInstanceOf(OpenAIResumeWordingClient::class);
});

it('lets a caller construct GenerateResumeVariant with an explicit OpenAIResumeWordingClient regardless of AI_RESUME_WORDING_PROVIDER', function () {
    config([
        'services.resume_wording.provider' => 'ollama',
        'services.ollama.base_url' => 'http://ollama.test:11434',
    ]);

    $generator = new GenerateResumeVariant(
        selectionProvider: app(GeneratesResumeSelection::class),
        wordingProvider: new OpenAIResumeWordingClient(
            apiKey: (string) config('services.openai.key'),
            model: (string) config('services.openai.model'),
        ),
        selectionPrompt: app(ResumeSelectionPromptV3::class),
        wordingPrompt: app(ResumeWordingPromptV2::class),
        candidateBuilder: app(ResumeCandidatePayloadBuilder::class),
        jobPayloadBuilder: app(JobPayloadBuilder::class),
        targetTerminologyBuilder: app(TargetTerminologyBuilder::class),
        selectionValidator: app(ResumeSelectionResponseValidator::class),
        wordingValidator: app(ResumeWordingResponseValidator::class),
    );

    $provider = (new ReflectionClass($generator))->getProperty('wordingProvider')->getValue($generator);

    expect($provider)->toBeInstanceOf(OpenAIResumeWordingClient::class);
});
