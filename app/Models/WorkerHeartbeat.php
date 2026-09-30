<?php

namespace App\Models;

use Database\Factories\WorkerHeartbeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The most recent sighting of one named external worker — upserted by
 * identity on every authenticated claim poll (see
 * App\Support\ApplicationInspection\RecordWorkerHeartbeat), never
 * accumulated as history. A small, purpose-built presence record, not
 * a generalized worker registry. See docs/application-inspector.md
 * "Worker presence".
 *
 * @property int $id
 * @property string $identity
 * @property string $worker_type
 * @property Carbon $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['identity', 'worker_type', 'last_seen_at'])]
class WorkerHeartbeat extends Model
{
    /** @use HasFactory<WorkerHeartbeatFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
