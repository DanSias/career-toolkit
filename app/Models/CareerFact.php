<?php

namespace App\Models;

use App\Contracts\HasCareerProfileOwnership;
use App\Enums\CareerFactType;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Exceptions\InvalidCareerFactAttributionException;
use Database\Factories\CareerFactFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * One atomic, selectable claim about a career: a bullet, a metric, a
 * capability, or a narrative statement.
 *
 * Always belongs to a CareerProfile directly (so "all facts for this
 * profile" never needs a polymorphic join), and separately carries an
 * attribution target — CareerProfile, Employer, Role, or Project — via a
 * polymorphic relation. The two are independent: a fact can be attributed
 * to a Project while still belonging to the CareerProfile that owns it.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $key
 * @property CareerFactType $fact_type
 * @property string $statement
 * @property Verification $verification
 * @property Visibility $visibility
 * @property string|null $notes
 * @property int $sort_order
 * @property string $attributable_type
 * @property int $attributable_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'career_profile_id',
    'key',
    'fact_type',
    'statement',
    'verification',
    'visibility',
    'notes',
    'sort_order',
    'attributable_type',
    'attributable_id',
])]
class CareerFact extends Model
{
    /** @use HasFactory<CareerFactFactory> */
    use HasFactory;

    /**
     * Route-model binds by the stable `key` column rather than the
     * auto-increment id, so URLs stay meaningful and stable across a
     * re-import. See docs/domain-model.md "Stable identifiers".
     */
    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected function casts(): array
    {
        return [
            'fact_type' => CareerFactType::class,
            'verification' => Verification::class,
            'visibility' => Visibility::class,
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
     * The CareerProfile, Employer, Role, or Project this fact is
     * attributed to.
     *
     * @return MorphTo<Model, $this>
     */
    public function attributable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Evidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * @return HasOne<Metric, $this>
     */
    public function metric(): HasOne
    {
        return $this->hasOne(Metric::class);
    }

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    /**
     * Re-point this fact's attribution back to the CareerProfile that owns
     * it. Used when the model it was attributed to (Employer, Role, or
     * Project) is being deleted, so the fact itself is never lost — see
     * docs/domain-model.md for why this is necessary.
     */
    public function reattributeToOwningProfile(): void
    {
        $this->update([
            'attributable_type' => (new CareerProfile)->getMorphClass(),
            'attributable_id' => $this->career_profile_id,
        ]);
    }

    /**
     * Re-point every fact currently attributed to the given morph alias/ids
     * back to its owning CareerProfile. Called from the `deleting` hook of
     * Employer, Role, and Project.
     *
     * @param  array<int, int>  $attributableIds
     */
    public static function reassignAttributionToProfile(string $morphAlias, array $attributableIds): void
    {
        if ($attributableIds === []) {
            return;
        }

        /** @var Collection<int, CareerFact> $facts */
        $facts = static::query()
            ->where('attributable_type', $morphAlias)
            ->whereIn('attributable_id', $attributableIds)
            ->get();

        $facts->each(fn (CareerFact $fact) => $fact->reattributeToOwningProfile());
    }

    /**
     * Enforces the one attribution invariant every writer must satisfy:
     * `attributable_type` must be a registered, ownership-aware morph
     * type; `attributable_id` must reference a row that exists; and that
     * row must belong to the same CareerProfile as this fact. Runs on
     * every save (`saving` fires for both create and update), so it
     * catches a bad attribution target on a new fact, a change to an
     * existing fact's `attributable_*`, and a change to
     * `career_profile_id` that would make an existing, previously-valid
     * attribution invalid — the same check covers all three, since it
     * only ever compares the two current in-memory values.
     *
     * This is the one centralized write path: any writer that goes
     * through Eloquent (factories, relationship-based creation, an
     * eventual importer, controllers) is covered automatically, with
     * nothing extra to call or remember. It cannot catch a raw SQL
     * INSERT/UPDATE that bypasses Eloquent entirely — see
     * docs/domain-model.md for that accepted limitation.
     */
    #[Boot]
    protected static function enforceAttributionIntegrity(): void
    {
        static::saving(function (self $fact) {
            $fact->assertValidAttribution();
        });
    }

    protected function assertValidAttribution(): void
    {
        $targetClass = Relation::morphMap()[$this->attributable_type] ?? null;

        if ($targetClass === null || ! is_a($targetClass, HasCareerProfileOwnership::class, true)) {
            throw new InvalidCareerFactAttributionException(
                "CareerFact attribution has an unsupported attributable type [{$this->attributable_type}]."
            );
        }

        /** @var (Model&HasCareerProfileOwnership)|null $target */
        $target = $targetClass::query()->find($this->attributable_id);

        if ($target === null) {
            throw new InvalidCareerFactAttributionException(
                "CareerFact attribution target [{$this->attributable_type} #{$this->attributable_id}] does not exist."
            );
        }

        if ($target->ownerCareerProfileId() !== $this->career_profile_id) {
            throw new InvalidCareerFactAttributionException(
                "CareerFact attribution target [{$this->attributable_type} #{$this->attributable_id}] ".
                "does not belong to CareerProfile #{$this->career_profile_id}."
            );
        }
    }
}
