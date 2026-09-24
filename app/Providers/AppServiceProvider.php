<?php

namespace App\Providers;

use App\Contracts\GeneratesJobAnalysis;
use App\Contracts\GeneratesJobMatch;
use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;
use App\Support\JobAnalysis\Providers\OllamaJobAnalysisClient;
use App\Support\JobAnalysis\Providers\OpenAIJobAnalysisClient;
use App\Support\JobMatch\Providers\OllamaJobMatchClient;
use App\Support\JobMatch\Providers\OpenAIJobMatchClient;
use App\Support\ResumeVariant\Providers\OllamaResumeSelectionClient;
use App\Support\ResumeVariant\Providers\OpenAIResumeSelectionClient;
use App\Support\ResumeVariant\Providers\OpenAIResumeWordingClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GeneratesJobAnalysis::class, fn () => $this->resolveJobAnalysisProvider());

        $this->app->bind(GeneratesJobMatch::class, fn () => $this->resolveJobMatchProvider());

        $this->app->bind(GeneratesResumeSelection::class, fn () => $this->resolveResumeSelectionProvider());

        $this->app->bind(GeneratesResumeWording::class, fn () => new OpenAIResumeWordingClient(
            apiKey: (string) config('services.openai.key'),
            model: (string) config('services.openai.model'),
        ));
    }

    /**
     * The one place GeneratesJobAnalysis's provider is chosen —
     * `services.job_analysis.provider` (AI_JOB_ANALYSIS_PROVIDER),
     * 'ollama' by default (local-first — see docs/job-analysis-generation.md
     * "Provider boundary"). OpenAI remains fully supported as an
     * explicitly selectable provider, never an automatic fallback.
     * GenerateJobAnalysis, its prompt, validator, and persistence never
     * see this decision — they depend only on the GeneratesJobAnalysis
     * interface, so adding a provider here is a new case in this match,
     * never a change to them. An unrecognized provider value fails fast
     * at resolution time rather than silently falling back to a
     * default. Resolving this binding only ever constructs a client
     * object — no inference occurs until something calls ->generate()
     * on the result.
     */
    private function resolveJobAnalysisProvider(): GeneratesJobAnalysis
    {
        $provider = (string) config('services.job_analysis.provider');
        $provider = $provider === '' ? 'ollama' : $provider;

        return match ($provider) {
            'openai' => new OpenAIJobAnalysisClient(
                apiKey: (string) config('services.openai.key'),
                model: (string) config('services.openai.model'),
            ),
            'ollama' => new OllamaJobAnalysisClient(
                baseUrl: (string) config('services.ollama.base_url'),
                model: (string) config('services.ollama.model'),
                timeoutSeconds: (int) config('services.ollama.timeout'),
            ),
            default => throw new InvalidArgumentException(
                "Unsupported AI_JOB_ANALYSIS_PROVIDER value [{$provider}]. Supported values: openai, ollama."
            ),
        };
    }

    /**
     * The one place GeneratesJobMatch's provider is chosen —
     * `services.job_match.provider` (AI_JOB_MATCH_PROVIDER), 'ollama'
     * by default (local-first — see docs/job-match-generation.md
     * "Provider boundary"). OpenAI remains fully supported as an
     * explicitly selectable provider, never an automatic fallback.
     * Mirrors resolveJobAnalysisProvider() exactly, including the
     * fail-fast behavior on an unrecognized value. Job Match uses its
     * own purpose-specific Ollama model/timeout
     * (`services.ollama.job_match_model`/`job_match_timeout`) rather
     * than Job Analysis's, so evaluating a different local model for
     * one purpose never silently changes the other. Resolving this
     * binding only ever constructs a client object — no inference
     * occurs until something calls ->generate() on the result.
     */
    private function resolveJobMatchProvider(): GeneratesJobMatch
    {
        $provider = (string) config('services.job_match.provider');
        $provider = $provider === '' ? 'ollama' : $provider;

        return match ($provider) {
            'openai' => new OpenAIJobMatchClient(
                apiKey: (string) config('services.openai.key'),
                model: (string) config('services.openai.model'),
            ),
            'ollama' => new OllamaJobMatchClient(
                baseUrl: (string) config('services.ollama.base_url'),
                model: (string) config('services.ollama.job_match_model'),
                timeoutSeconds: (int) config('services.ollama.job_match_timeout'),
            ),
            default => throw new InvalidArgumentException(
                "Unsupported AI_JOB_MATCH_PROVIDER value [{$provider}]. Supported values: openai, ollama."
            ),
        };
    }

    /**
     * The one place GeneratesResumeSelection's provider is chosen —
     * `services.resume_selection.provider` (AI_RESUME_SELECTION_PROVIDER),
     * 'ollama' by default (local-first — see
     * docs/resume-variant-generation.md "Provider boundary"). OpenAI
     * remains fully supported as an explicitly selectable provider,
     * never an automatic fallback. Mirrors resolveJobMatchProvider()
     * exactly, including the fail-fast behavior on an unrecognized
     * value and the purpose-specific Ollama model/timeout
     * (`services.ollama.resume_selection_model`/`resume_selection_timeout`)
     * so evaluating a different local model for this purpose never
     * silently changes Job Analysis or Job Match. Resolving this
     * binding only ever constructs a client object — no inference
     * occurs until something calls ->generate() on the result. Resume
     * Wording remains OpenAI-only above — no local implementation
     * exists yet for that stage.
     */
    private function resolveResumeSelectionProvider(): GeneratesResumeSelection
    {
        $provider = (string) config('services.resume_selection.provider');
        $provider = $provider === '' ? 'ollama' : $provider;

        return match ($provider) {
            'openai' => new OpenAIResumeSelectionClient(
                apiKey: (string) config('services.openai.key'),
                model: (string) config('services.openai.model'),
            ),
            'ollama' => new OllamaResumeSelectionClient(
                baseUrl: (string) config('services.ollama.base_url'),
                model: (string) config('services.ollama.resume_selection_model'),
                timeoutSeconds: (int) config('services.ollama.resume_selection_timeout'),
            ),
            default => throw new InvalidArgumentException(
                "Unsupported AI_RESUME_SELECTION_PROVIDER value [{$provider}]. Supported values: openai, ollama."
            ),
        };
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Use short, stable aliases for CareerFact's polymorphic attribution
     * targets instead of full class names, so a future namespace refactor
     * can't silently orphan stored attributions. Enforced (not just
     * mapped), so an un-mapped model can never be used as an attributable
     * target by accident. See docs/domain-model.md.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'career_profile' => CareerProfile::class,
            'employer' => Employer::class,
            'role' => Role::class,
            'project' => Project::class,
        ]);
    }
}
