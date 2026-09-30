<?php

namespace App\Models;

use App\Enums\JobCanonicalSource;
use Database\Factories\KnownAtsBoardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A small, explicit company→ATS-board resolution/enrichment mapping —
 * see App\Support\JobDiscovery\Canonical\ResolveAtsBoard and this
 * table's own migration docblock for why this is deliberately not the
 * future target-company-monitoring feature.
 *
 * @property int $id
 * @property string $company_name
 * @property JobCanonicalSource $ats_type
 * @property string $board_identifier
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_name', 'ats_type', 'board_identifier'])]
class KnownAtsBoard extends Model
{
    /** @use HasFactory<KnownAtsBoardFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ats_type' => JobCanonicalSource::class,
        ];
    }
}
