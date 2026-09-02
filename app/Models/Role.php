<?php

namespace App\Models;

use App\Contracts\HasCareerProfileOwnership;
use App\Exceptions\InvalidRoleDateRangeException;
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
 * Dates are month/year precision only (`start_year`/`start_month`,
 * `end_year`/`end_month`) — no source ever evidences a day of month, and
 * a SQL DATE column would silently assert precision nobody has. See
 * docs/domain-model.md.
 *
 * @property int $id
 * @property int $employer_id
 * @property string $title
 * @property int $start_year
 * @property int|null $start_month
 * @property int|null $end_year
 * @property int|null $end_month
 * @property string|null $summary
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['employer_id', 'title', 'start_year', 'start_month', 'end_year', 'end_month', 'summary', 'sort_order'])]
class Role extends Model implements HasCareerProfileOwnership
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

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

    /**
     * Enforces the date invariants a month/year-precision range needs:
     * months (when present) are valid calendar months, an end month can't
     * exist without an end year, and the end (year, and month within the
     * same year) can't precede the start. Runs on every save via the
     * `saving` event, same convention as CareerFact's attribution check.
     */
    #[Boot]
    protected static function enforceValidDateRange(): void
    {
        static::saving(function (self $role) {
            $role->assertValidDateRange();
        });
    }

    protected function assertValidDateRange(): void
    {
        foreach (['start_month' => $this->start_month, 'end_month' => $this->end_month] as $field => $month) {
            if ($month !== null && ($month < 1 || $month > 12)) {
                throw new InvalidRoleDateRangeException("Role {$field} must be between 1 and 12, got {$month}.");
            }
        }

        if ($this->end_month !== null && $this->end_year === null) {
            throw new InvalidRoleDateRangeException('Role end_month cannot be set without end_year.');
        }

        if ($this->end_year !== null) {
            if ($this->end_year < $this->start_year) {
                throw new InvalidRoleDateRangeException('Role end_year cannot be before start_year.');
            }

            if ($this->end_year === $this->start_year
                && $this->end_month !== null
                && $this->start_month !== null
                && $this->end_month < $this->start_month) {
                throw new InvalidRoleDateRangeException('Role end_month cannot be before start_month within the same year.');
            }
        }
    }
}
