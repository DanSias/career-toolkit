<?php

namespace App\Models;

use App\Contracts\HasCareerProfileOwnership;
use App\Enums\Visibility;
use App\Exceptions\InvalidProjectOwnershipException;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A named, evidenced body of work — either professional (owned by a
 * Role) or independent/personal (owned directly by a CareerProfile,
 * with no Role at all).
 *
 * `career_profile_id` is required on every Project; `role_id` is
 * nullable. `role_id !== null` means a professional project owned by
 * that Role (its `career_profile_id` must match that Role's own
 * owning CareerProfile — enforced below); `role_id === null` means an
 * independent project belonging directly to the CareerProfile. One
 * uniform ownership column for both kinds, rather than an
 * either/or (XOR) pair — see docs/domain-model.md "Project ownership".
 *
 * Deliberately thin: ownership breadth, collaboration context, metrics,
 * and architecture detail are NOT columns here — they're CareerFacts
 * attributed to this Project, because each of those claims can carry its
 * own independent evidence and verification status. See
 * docs/domain-model.md.
 *
 * @property int $id
 * @property int|null $career_profile_id Required by the database (NOT NULL) once saved — nullable here only because a professional Project's caller may omit it and rely on enforceOwnershipIntegrity() to auto-fill it from role_id before the row is actually written.
 * @property int|null $role_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Visibility|null $default_visibility
 * @property string|null $live_url
 * @property string|null $repository_url
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['career_profile_id', 'role_id', 'name', 'slug', 'description', 'default_visibility', 'live_url', 'repository_url', 'sort_order'])]
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
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }

    /**
     * Null for an independent project — see class docblock.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Direct, explicit Project-level skill assertions — distinct from
     * derivedSkills() below. Currently unpopulated across the whole
     * dataset (`project_skill` is empty) and unused by the UI, which
     * reads derivedSkills() instead. Kept, not removed: see
     * docs/domain-model.md "Project skills are derived, not stored" for
     * when this would actually get used.
     *
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
     * The Project's Skills, derived from the Skills attached to
     * CareerFacts attributed *directly* to this Project — never from
     * project_skill, and never from Role-level facts. See
     * docs/domain-model.md "Project skills are derived, not stored".
     *
     * Reads `careerFacts.skills` off already-loaded relations when
     * eager-loaded by the caller (recommended for a list of Projects, to
     * avoid N+1); falls back to lazy-loading them otherwise.
     *
     * @return Collection<int, Skill>
     */
    public function derivedSkills(): Collection
    {
        return $this->careerFacts
            ->flatMap(fn (CareerFact $fact) => $fact->skills)
            ->unique('id')
            ->values();
    }

    public function ownerCareerProfileId(): ?int
    {
        return $this->career_profile_id;
    }

    /**
     * Enforces the one ownership invariant every writer must satisfy:
     * when `role_id` is set, that Role must exist and its own owning
     * CareerProfile must match this Project's `career_profile_id` —
     * deterministically checked, never assumed. Mirrors
     * `CareerFact::enforceAttributionIntegrity()`'s `saving`-listener
     * convention exactly.
     *
     * `career_profile_id` is auto-filled from the Role's owning
     * CareerProfile when a professional Project's caller supplies
     * `role_id` but omits it — a convenience that keeps every existing
     * `Project::factory()->for($role)`-style call site and the
     * canonical importer's role-attached projects working unchanged.
     * It is never auto-filled for an independent Project (`role_id`
     * null): the caller must supply `career_profile_id` explicitly,
     * since there is no Role to derive it from.
     */
    #[Boot]
    protected static function enforceOwnershipIntegrity(): void
    {
        static::saving(function (self $project) {
            if ($project->role_id === null) {
                if ($project->career_profile_id === null) {
                    throw new InvalidProjectOwnershipException(
                        'An independent Project (role_id null) must have an explicit career_profile_id.'
                    );
                }

                return;
            }

            $role = Role::query()->with('employer')->find($project->role_id);

            if ($role === null) {
                throw new InvalidProjectOwnershipException(
                    "Project references role_id [{$project->role_id}], which does not exist."
                );
            }

            $roleOwnerId = $role->employer?->career_profile_id;

            if ($project->career_profile_id === null) {
                $project->career_profile_id = $roleOwnerId;

                return;
            }

            if ($roleOwnerId !== $project->career_profile_id) {
                throw new InvalidProjectOwnershipException(
                    "Project's role_id [{$project->role_id}] belongs to CareerProfile #{$roleOwnerId}, ".
                    "not CareerProfile #{$project->career_profile_id}."
                );
            }
        });
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
