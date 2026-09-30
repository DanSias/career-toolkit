<?php

namespace App\Models;

use App\Enums\DiscoveryStatus;
use Database\Factories\DiscoveryRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One durable execution of the discovery pipeline (all configured
 * providers, one run) — see this table's migration docblock for why
 * this is a deliberately new shape, not WorkflowRun/AgentRun or
 * GenerationAttempt.
 *
 * @property int $id
 * @property DiscoveryStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['status', 'started_at', 'finished_at'])]
class DiscoveryRun extends Model
{
    /** @use HasFactory<DiscoveryRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DiscoveryStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DiscoveryProviderAttempt, $this>
     */
    public function providerAttempts(): HasMany
    {
        return $this->hasMany(DiscoveryProviderAttempt::class);
    }
}
