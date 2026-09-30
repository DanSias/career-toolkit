<?php

namespace App\Models;

use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Enums\JobPostingLifecycleStatus;
use App\Enums\JobRemoteStatus;
use Carbon\CarbonImmutable;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The source material for a target job. Two distinct lifecycles live
 * on this one model, discriminated by `discovery_source`, deliberately
 * not split into separate models (see docs/job-discovery.md "Manual
 * vs. discovered semantics" for why a single model was judged the
 * cleaner representation):
 *
 * - **Manual** (`discovery_source: manual`, the column default): the
 *   original semantics, unchanged — verbatim source material captured
 *   once at intake and never rewritten. No analysis, keywords,
 *   requirements, match score, or selected CareerFacts live here
 *   either. A JobPosting may accumulate zero or more JobAnalysis
 *   snapshots over time (re-analysis creates a new one rather than
 *   overwriting), but there is no "current" pointer here — see
 *   docs/domain-model.md "JobPosting" and "JobAnalysis".
 * - **Discovered** (`discovery_source` a real provider): a live
 *   external posting, periodically refreshed in place by
 *   App\Support\JobDiscovery — title/description/location/
 *   compensation/remote_status/source_updated_at/last_checked_at may
 *   all legitimately change while the same underlying posting remains
 *   open. Provenance fields (discovery_source, discovery_source_id,
 *   discovered_at, canonical_source, canonical_source_id) are
 *   write-once by discovery's own ingestion logic — see
 *   isDiscovered() and App\Support\JobDiscovery\
 *   IngestDiscoveredCandidate.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $company
 * @property string $title
 * @property string|null $source_url
 * @property string|null $location
 * @property string $description
 * @property JobDiscoverySource $discovery_source
 * @property string|null $discovery_source_id
 * @property JobCanonicalSource|null $canonical_source
 * @property string|null $canonical_source_id
 * @property JobRemoteStatus|null $remote_status
 * @property string|null $employment_type
 * @property int|null $compensation_min
 * @property int|null $compensation_max
 * @property string|null $compensation_currency
 * @property string|null $compensation_interval
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable|null $source_updated_at
 * @property CarbonImmutable $discovered_at
 * @property JobPostingLifecycleStatus $status
 * @property CarbonImmutable|null $last_checked_at
 * @property array<string, mixed>|null $discovery_metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'career_profile_id', 'company', 'title', 'source_url', 'location', 'description',
    'discovery_source', 'discovery_source_id', 'canonical_source', 'canonical_source_id',
    'remote_status', 'employment_type',
    'compensation_min', 'compensation_max', 'compensation_currency', 'compensation_interval',
    'posted_at', 'source_updated_at', 'discovered_at', 'status', 'last_checked_at',
    'discovery_metadata',
])]
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    /**
     * Set in-memory defaults on create, mirroring this codebase's
     * established convention (App\Models\Application never relies on
     * a DB column default for its own status either) precisely
     * because a DB-level default alone would leave the in-memory
     * model's enum-cast attribute unset/null immediately after
     * create() — SQLite/PDO doesn't return computed column defaults
     * on INSERT, so isDiscovered() could misreport on an unrefreshed
     * instance. The migration's DB-level defaults remain as a
     * defensive backstop for any insert path that bypasses Eloquent,
     * never the source of truth callers should rely on.
     */
    protected static function booted(): void
    {
        static::creating(function (JobPosting $job) {
            $job->discovery_source ??= JobDiscoverySource::Manual;
            $job->status ??= JobPostingLifecycleStatus::Open;
            $job->discovered_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'discovery_source' => JobDiscoverySource::class,
            'canonical_source' => JobCanonicalSource::class,
            'remote_status' => JobRemoteStatus::class,
            'status' => JobPostingLifecycleStatus::class,
            'posted_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'discovered_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'discovery_metadata' => 'array',
        ];
    }

    /**
     * False for every hand-created row (discovery_source: manual, the
     * column default) — true for anything App\Support\JobDiscovery
     * ingested. See this class's own docblock.
     */
    public function isDiscovered(): bool
    {
        return $this->discovery_source !== JobDiscoverySource::Manual;
    }

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

    /**
     * Every attempt (zero or more) to evaluate or pursue this posting —
     * see App\Models\Application and docs/domain-model.md
     * "Application".
     *
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Every job_analysis GenerationAttempt ever made against this
     * posting — queued, running, succeeded, or failed. See
     * App\Models\GenerationAttempt and docs/job-analysis-generation.md
     * "Durable generation attempts (foundation)".
     *
     * @return MorphMany<GenerationAttempt, $this>
     */
    public function generationAttempts(): MorphMany
    {
        return $this->morphMany(GenerationAttempt::class, 'subject');
    }
}
