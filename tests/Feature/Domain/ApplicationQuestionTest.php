<?php

use App\Enums\ApplicationQuestionExtractionSource;
use App\Enums\ApplicationQuestionLabelSource;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use Illuminate\Database\QueryException;

it('belongs to an application', function () {
    $application = Application::factory()->create();
    $question = ApplicationQuestion::factory()->for($application)->create();

    expect($question->application->is($application))->toBeTrue();
});

it('belongs to the agent run that produced it', function () {
    $agentRun = AgentRun::factory()->create();
    $question = ApplicationQuestion::factory()->for($agentRun, 'agentRun')->create();

    expect($question->agentRun->is($agentRun))->toBeTrue();
});

it('lets an application own multiple questions', function () {
    $application = Application::factory()->create();
    ApplicationQuestion::factory()->for($application)->count(3)->create();

    expect($application->applicationQuestions)->toHaveCount(3);
});

it('lets an agent run produce multiple questions', function () {
    $agentRun = AgentRun::factory()->create();
    ApplicationQuestion::factory()->for($agentRun, 'agentRun')->count(3)->create();

    expect($agentRun->applicationQuestions)->toHaveCount(3);
});

it('casts label_source and extraction_source to their enums', function () {
    $question = ApplicationQuestion::factory()->create([
        'label_source' => ApplicationQuestionLabelSource::DomAriaLabelledby,
        'extraction_source' => ApplicationQuestionExtractionSource::Both,
    ]);

    expect($question->fresh()->label_source)->toBe(ApplicationQuestionLabelSource::DomAriaLabelledby)
        ->and($question->fresh()->extraction_source)->toBe(ApplicationQuestionExtractionSource::Both);
});

it('casts options to an array', function () {
    $question = ApplicationQuestion::factory()->create([
        'options' => ['United States', 'Canada', 'United Kingdom'],
    ]);

    expect($question->fresh()->options)->toBe(['United States', 'Canada', 'United Kingdom']);
});

it('allows raw_label, required, and options to be null — an unresolved field is honest, not an error', function () {
    $question = ApplicationQuestion::factory()->create([
        'raw_label' => null,
        'label_source' => ApplicationQuestionLabelSource::Unresolved,
        'required' => null,
        'options' => null,
    ]);
    $question = $question->fresh();

    expect($question->raw_label)->toBeNull()
        ->and($question->required)->toBeNull()
        ->and($question->options)->toBeNull()
        ->and($question->label_source)->toBe(ApplicationQuestionLabelSource::Unresolved);
});

it('persists an explicit, zero-based position that is never inferred from insertion order', function () {
    $application = Application::factory()->create();
    $agentRun = AgentRun::factory()->create();

    // Deliberately inserted out of source-form order, to prove position
    // — not id — carries the real document order.
    $second = ApplicationQuestion::factory()->for($application)->for($agentRun, 'agentRun')->create(['position' => 1]);
    $first = ApplicationQuestion::factory()->for($application)->for($agentRun, 'agentRun')->create(['position' => 0]);

    expect($first->id)->toBeGreaterThan($second->id)
        ->and($first->position)->toBe(0)
        ->and($second->position)->toBe(1);

    $orderedByPosition = $application->applicationQuestions()->orderBy('position')->pluck('id')->all();

    expect($orderedByPosition)->toBe([$first->id, $second->id]);
});

it('cannot be created against a nonexistent agent run', function () {
    expect(fn () => ApplicationQuestion::factory()->create(['agent_run_id' => 0]))
        ->toThrow(QueryException::class);
});
