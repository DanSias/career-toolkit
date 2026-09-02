<?php

namespace App\Models;

use App\Enums\SkillCategory;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A build technology, platform integration, engineering capability, or
 * practice/workflow. Deliberately holds no intrinsic "evidence strength" —
 * that's derivable from the CareerFacts and Projects that reference it,
 * not a permanent property of the Skill itself. See docs/domain-model.md.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $name
 * @property string $slug
 * @property SkillCategory $category
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['career_profile_id', 'name', 'slug', 'category', 'description'])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => SkillCategory::class,
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
     * @return BelongsToMany<CareerFact, $this>
     */
    public function careerFacts(): BelongsToMany
    {
        return $this->belongsToMany(CareerFact::class);
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }
}
