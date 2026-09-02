<?php

namespace App\Models;

use App\Enums\EvidenceSource;
use Database\Factories\EvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of provenance for a CareerFact: where the claim came from, and
 * enough of a locator to find it again. A fact typically has more than one
 * of these.
 *
 * The literal wording used by this particular source (`quoted_text`) lives
 * here rather than in a separate entity — it's naturally 1:1 with a given
 * source+locator and doesn't need an independent lifecycle. See
 * docs/domain-model.md.
 *
 * @property int $id
 * @property int $career_fact_id
 * @property EvidenceSource $source
 * @property string|null $document
 * @property string|null $path
 * @property string|null $section
 * @property string|null $locator
 * @property string|null $quoted_text
 * @property Carbon|null $confirmed_at
 * @property string|null $note
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('career_fact_evidence')]
#[Fillable([
    'career_fact_id',
    'source',
    'document',
    'path',
    'section',
    'locator',
    'quoted_text',
    'confirmed_at',
    'note',
    'metadata',
])]
class Evidence extends Model
{
    /** @use HasFactory<EvidenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source' => EvidenceSource::class,
            'confirmed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CareerFact, $this>
     */
    public function careerFact(): BelongsTo
    {
        return $this->belongsTo(CareerFact::class);
    }
}
