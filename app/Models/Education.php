<?php

namespace App\Models;

use Database\Factories\EducationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One academic credential belonging to a CareerProfile. Plain structured
 * profile data — not a CareerFact: it carries no verification, visibility,
 * or Evidence of its own, the same way Employer doesn't. See
 * docs/domain-model.md.
 *
 * Dates are year precision only — no source evidences a month or day for
 * education dates.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $institution
 * @property string $degree
 * @property string|null $field_of_study
 * @property int|null $start_year
 * @property int|null $end_year
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('educations')]
#[Fillable(['career_profile_id', 'institution', 'degree', 'field_of_study', 'start_year', 'end_year', 'sort_order'])]
class Education extends Model
{
    /** @use HasFactory<EducationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }
}
