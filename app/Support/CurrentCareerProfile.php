<?php

namespace App\Support;

use App\Exceptions\NoCareerProfileException;
use App\Models\CareerProfile;

/**
 * Centralizes "which CareerProfile is the active one" for this
 * local-first, single-user application. Not a tenancy/context framework —
 * there is no login, no session-scoped profile, and no switching. It
 * exists purely so the resolution rule (and what happens when it can't be
 * satisfied) lives in one place instead of being re-decided by every
 * controller that needs a CareerProfile.
 *
 * Deterministic rule when more than one CareerProfile exists (this
 * product doesn't create more than one today, but nothing prevents it at
 * the database level): the oldest one, i.e. the one with the lowest id —
 * effectively "the first CareerProfile this application ever owned."
 */
final class CurrentCareerProfile
{
    /**
     * Resolves the current CareerProfile, or throws.
     *
     * Use this wherever the caller genuinely requires a profile to exist
     * to do its job (e.g. creating a JobPosting) — a missing profile is a
     * real failure there, not a state to render around.
     *
     * @throws NoCareerProfileException
     */
    public static function resolve(): CareerProfile
    {
        return self::tryResolve()
            ?? throw new NoCareerProfileException(
                'No CareerProfile exists yet. Run `php artisan career:import` to create one from the canonical dataset.'
            );
    }

    /**
     * Resolves the current CareerProfile, or null.
     *
     * Use this only where the caller has a deliberate, already-designed
     * empty state for "no profile yet" — today, that's just the Career
     * Data Explorer's landing page on a freshly migrated, not-yet-imported
     * database.
     */
    public static function tryResolve(): ?CareerProfile
    {
        return CareerProfile::query()->oldest('id')->first();
    }
}
