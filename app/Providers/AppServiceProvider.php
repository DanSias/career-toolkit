<?php

namespace App\Providers;

use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
