<?php

namespace App\Models;

use App\Contracts\HasCareerProfileOwnership;
use Database\Factories\CareerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person's canonical career dataset: the ownership root for their
 * Employers, CareerFacts, and Skills.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'name'])]
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
     * @return HasMany<CareerFact, $this>
     */
    public function careerFacts(): HasMany
    {
        return $this->hasMany(CareerFact::class);
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

    public function ownerCareerProfileId(): ?int
    {
        return $this->id;
    }
}
