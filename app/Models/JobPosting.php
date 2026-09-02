<?php

namespace App\Models;

use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The verbatim source material for a target job — captured once at
 * intake and never rewritten. Deliberately holds nothing beyond the
 * source itself as columns: no analysis, keywords, requirements, match
 * score, or selected CareerFacts. A JobPosting may accumulate zero or
 * more JobAnalysis snapshots over time (re-analysis creates a new one
 * rather than overwriting), but there is no "current" pointer here —
 * see docs/domain-model.md "JobPosting" and "JobAnalysis".
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $company
 * @property string $title
 * @property string|null $source_url
 * @property string|null $location
 * @property string $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['career_profile_id', 'company', 'title', 'source_url', 'location', 'description'])]
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }

    /**
     * @return HasMany<JobAnalysis, $this>
     */
    public function jobAnalyses(): HasMany
    {
        return $this->hasMany(JobAnalysis::class);
    }
}
