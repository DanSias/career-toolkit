<?php

namespace App\Models;

use App\Contracts\HasCareerProfileOwnership;
use Database\Factories\CareerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A person's canonical career dataset: the ownership root for their
 * Employers, CareerFacts, and Skills.
 *
 * `email`/`phone`/`location`/`portfolio_url`/`github_url` are
 * resume-facing contact fields — deliberately separate from
 * `User.email` (the login credential; see `App\Models\User`). All are
 * nullable and populated only via the canonical import source
 * (`data/canonical-career-data.proposed.json`'s `career_profile`
 * object), never by ad-hoc seeding — see
 * `docs/canonical-data-proposal.md`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $location
 * @property string|null $portfolio_url
 * @property string|null $github_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'name', 'email', 'phone', 'location', 'portfolio_url', 'github_url'])]
class CareerProfile extends Model implements HasCareerProfileOwnership
{
    /** @use HasFactory<CareerProfileFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Employer, $this>
     */
    public function employers(): HasMany
    {
        return $this->hasMany(Employer::class);
    }

    /**
     * Every CareerFact this profile owns, regardless of what it's
     * attributed to — see docs/domain-model.md, "CareerFact has two
     * relationships to the rest of the graph."
     *
     * @return HasMany<CareerFact, $this>
     */
    public function careerFacts(): HasMany
    {
        return $this->hasMany(CareerFact::class);
    }

    /**
     * CareerFacts attributed directly to the CareerProfile itself, as
     * opposed to one of its Employers, Roles, or Projects. Named
     * distinctly from careerFacts() above since that name is already
     * taken by "every fact this profile owns."
     *
     * @return MorphMany<CareerFact, $this>
     */
    public function directCareerFacts(): MorphMany
    {
        return $this->morphMany(CareerFact::class, 'attributable');
    }

    /**
     * @return HasMany<Skill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }

    /**
     * @return HasMany<Education, $this>
     */
    public function educations(): HasMany
    {
        return $this->hasMany(Education::class);
    }

    /**
     * @return HasMany<JobPosting, $this>
     */
    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    /**
     * Zero or more JobMatch runs comparing this profile's canonical
     * data against a JobAnalysis. See docs/domain-model.md "JobMatch".
     *
     * @return HasMany<JobMatch, $this>
     */
    public function jobMatches(): HasMany
    {
        return $this->hasMany(JobMatch::class);
    }

    /**
     * Zero or more ResumeVariant generations built for this profile.
     * See docs/domain-model.md "ResumeVariant".
     *
     * @return HasMany<ResumeVariant, $this>
     */
    public function resumeVariants(): HasMany
    {
        return $this->hasMany(ResumeVariant::class);
    }

    public function ownerCareerProfileId(): ?int
    {
        return $this->id;
    }
}
