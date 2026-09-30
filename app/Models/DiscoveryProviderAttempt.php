<?php

namespace App\Models;

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use Database\Factories\DiscoveryProviderAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One provider's attempt within a DiscoveryRun — see that model's
 * docblock. Counts only (candidates_retrieved/accepted, jobs_created/
 * updated/canonicalized) — never a per-candidate audit trail; see
 * this table's migration docblock.
 *
 * @property int $id
 * @property int $discovery_run_id
 * @property JobDiscoverySource $provider
 * @property DiscoveryStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $candidates_retrieved
 * @property int|null $candidates_accepted
 * @property int|null $jobs_created
 * @property int|null $jobs_updated
 * @property int|null $jobs_canonicalized
 * @property string|null $failure_category
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'discovery_run_id', 'provider', 'status', 'started_at', 'finished_at',
    'candidates_retrieved', 'candidates_accepted', 'jobs_created', 'jobs_updated', 'jobs_canonicalized',
    'failure_category', 'failure_message',
])]
class DiscoveryProviderAttempt extends Model
{
    /** @use HasFactory<DiscoveryProviderAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => JobDiscoverySource::class,
            'status' => DiscoveryStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DiscoveryRun, $this>
     */
    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class);
    }
}
