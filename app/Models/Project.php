<?php

namespace App\Models;

use App\Contracts\HasCareerProfileOwnership;
use App\Enums\Visibility;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A named, evidenced body of work within a Role. Zero or many per Role.
 *
 * Deliberately thin: ownership breadth, collaboration context, metrics,
 * and architecture detail are NOT columns here — they're CareerFacts
 * attributed to this Project, because each of those claims can carry its
 * own independent evidence and verification status. See
 * docs/domain-model.md.
 *
 * @property int $id
 * @property int $role_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Visibility|null $default_visibility
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['role_id', 'name', 'slug', 'description', 'default_visibility', 'sort_order'])]
class Project extends Model implements HasCareerProfileOwnership
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'default_visibility' => Visibility::class,
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    /**
     * CareerFacts attributed to this Project.
     *
     * @return MorphMany<CareerFact, $this>
     */
    public function careerFacts(): MorphMany
    {
        return $this->morphMany(CareerFact::class, 'attributable');
    }

    /**
     * Traverses Role -> Employer rather than storing its own
     * `career_profile_id` — see docs/domain-model.md.
     */
    public function ownerCareerProfileId(): ?int
    {
        return $this->role?->employer?->career_profile_id;
    }

    /**
     * Reassign any CareerFacts attributed to this Project back to its
     * owning CareerProfile before it is deleted, so removing a project
     * never destroys a canonical fact. See docs/domain-model.md.
     */
    #[Boot]
    protected static function reassignCareerFactsOnDelete(): void
    {
        static::deleting(function (self $project) {
            CareerFact::reassignAttributionToProfile($project->getMorphClass(), [$project->id]);
        });
    }
}
