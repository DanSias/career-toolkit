<?php

use App\Models\Application;

it('returns the same shape ApplicationController::show() uses for initial props', function () {
    $application = Application::factory()->create();

    $response = $this->getJson(route('applications.status', $application));

    $response->assertOk()->assertJson([
        'application_id' => $application->id,
        'workflow_run' => null,
        'latest_result' => null,
    ]);
});
