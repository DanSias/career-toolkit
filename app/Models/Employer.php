<?php

namespace App\Models;

use App\Contracts\HasCareerProfileOwnership;
use Database\Factories\EmployerFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * An organization/employer. Employment dates live on Role, not here — one
 * Employer can contain multiple successive Roles.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $name
 * @property string|null $short_name
 * @property string|null $description
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['career_profile_id', 'name', 'short_name', 'description', 'sort_order'])]
class Employer extends Model implements HasCareerProfileOwnership
{
    /** @use HasFactory<EmployerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }

    public function ownerCareerProfileId(): ?int
    {
        return $this->career_profile_id;
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * CareerFacts attributed directly to this Employer (as opposed to one
     * of its Roles or Projects).
     *
     * @return MorphMany<CareerFact, $this>
     */
    public function careerFacts(): MorphMany
    {
        return $this->morphMany(CareerFact::class, 'attributable');
    }

    /**
     * Before an Employer is deleted, its Roles and their Projects cascade
     * away at the database level (see the roles/projects migrations) — a
     * cascade that does not fire Eloquent's `deleting` event for the rows
     * it removes. Any CareerFact attributed to this Employer, or to any
     * Role/Project underneath it, is reassigned back to the owning
     * CareerProfile first, so deleting organizational structure never
     * silently destroys a canonical fact. See docs/domain-model.md.
     */
    #[Boot]
    protected static function reassignCareerFactsOnDelete(): void
    {
        static::deleting(function (self $employer) {
            $roleIds = $employer->roles()->pluck('id')->all();
            $projectIds = Project::query()->whereIn('role_id', $roleIds)->pluck('id')->all();

            CareerFact::reassignAttributionToProfile((new Project)->getMorphClass(), $projectIds);
            CareerFact::reassignAttributionToProfile((new Role)->getMorphClass(), $roleIds);
            CareerFact::reassignAttributionToProfile($employer->getMorphClass(), [$employer->id]);
        });
    }
}
