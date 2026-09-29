<?php

namespace App\Models;

use App\Enums\ApplicationQuestionExtractionSource;
use App\Enums\ApplicationQuestionLabelSource;
use Database\Factories\ApplicationQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One field/question discovered on an external application, produced by
 * exactly one AgentRun. Records only what was found — question
 * normalization and answer resolution are later, separate concerns not
 * represented here. See docs/domain-model.md "Application"
 * ("ApplicationQuestion") and docs/application-inspector.md
 * "ApplicationQuestion ordering".
 *
 * position is explicit, required, and zero-based, recording the
 * field's actual position in the source form's own document order —
 * real domain information, never inferred from id/insertion order.
 *
 * raw_label, required, and options may all be null: an honest
 * "unresolved" is a legitimate extraction outcome, never a forced
 * guess.
 *
 * @property int $id
 * @property int $application_id
 * @property int $agent_run_id
 * @property int $position
 * @property string|null $external_field_id
 * @property string|null $raw_label
 * @property ApplicationQuestionLabelSource $label_source
 * @property string $control_type
 * @property bool|null $required
 * @property array<int, string>|null $options
 * @property string|null $section
 * @property ApplicationQuestionExtractionSource $extraction_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'application_id',
    'agent_run_id',
    'position',
    'external_field_id',
    'raw_label',
    'label_source',
    'control_type',
    'required',
    'options',
    'section',
    'extraction_source',
])]
class ApplicationQuestion extends Model
{
    /** @use HasFactory<ApplicationQuestionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'label_source' => ApplicationQuestionLabelSource::class,
            'required' => 'boolean',
            'options' => 'array',
            'extraction_source' => ApplicationQuestionExtractionSource::class,
        ];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
