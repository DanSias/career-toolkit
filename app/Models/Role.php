<?php

namespace App\Models;

use App\Contracts\HasCareerProfileOwnership;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One distinct title/period of employment at an Employer. First-class
 * rather than folded into Employer, because one Employer can contain
 * several successive Roles with different titles, dates, and facts (see
 * docs/domain-model.md — the Pearson case is why this exists).
 *
 * @property int $id
 * @property int $employer_id
 * @property string $title
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property string|null $summary
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['employer_id', 'title', 'start_date', 'end_date', 'summary', 'sort_order'])]
class Role extends Model implements HasCareerProfileOwnership
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employer, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * CareerFacts attributed directly to this Role (as opposed to one of
     * its Projects).
     *
     * @return MorphMany<CareerFact, $this>
     */
    public function careerFacts(): MorphMany
    {
        return $this->morphMany(CareerFact::class, 'attributable');
    }

    /**
     * Traverses to Employer rather than storing its own
     * `career_profile_id` — see docs/domain-model.md.
     */
    public function ownerCareerProfileId(): ?int
    {
        return $this->employer?->career_profile_id;
    }

    /**
     * Before a Role is deleted, its Projects cascade away at the database
     * level without firing their own `deleting` event. Any CareerFact
     * attributed to this Role, or to a Project underneath it, is
     * reassigned back to the owning CareerProfile first. See
     * docs/domain-model.md.
     */
    #[Boot]
    protected static function reassignCareerFactsOnDelete(): void
    {
        static::deleting(function (self $role) {
            $projectIds = $role->projects()->pluck('id')->all();

            CareerFact::reassignAttributionToProfile((new Project)->getMorphClass(), $projectIds);
            CareerFact::reassignAttributionToProfile($role->getMorphClass(), [$role->id]);
        });
    }
}
