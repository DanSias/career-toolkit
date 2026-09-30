<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationInspectionStatusController;
use App\Http\Controllers\BrowserWorkerStatusController;
use App\Http\Controllers\CareerDataController;
use App\Http\Controllers\GenerationAttemptController;
use App\Http\Controllers\JobAnalysisController;
use App\Http\Controllers\JobMatchController;
use App\Http\Controllers\JobPostingController;
use App\Http\Controllers\ResumeVariantController;
use App\Http\Controllers\ResumeVariantPdfController;
use App\Http\Controllers\ResumeVariantPreviewController;
use App\Http\Middleware\AllowLongRunningGeneration;
use Illuminate\Support\Facades\Route;

Route::get('/', [CareerDataController::class, 'index'])->name('career-data.index');
Route::get('/career-data/facts/{careerFact}', [CareerDataController::class, 'showFact'])->name('career-data.facts.show');

Route::get('/jobs', [JobPostingController::class, 'index'])->name('jobs.index');
Route::get('/jobs/create', [JobPostingController::class, 'create'])->name('jobs.create');
Route::post('/jobs', [JobPostingController::class, 'store'])->name('jobs.store');
Route::get('/jobs/{jobPosting}', [JobPostingController::class, 'show'])->name('jobs.show');

// Job Analysis generation is queued (GenerateJobAnalysisJob) — this
// POST only creates/looks up a GenerationAttempt and dispatches, so it
// no longer needs AllowLongRunningGeneration; the actual, potentially
// multi-minute Ollama call now happens in the queue worker's own
// process, not this request's. See
// docs/job-analysis-generation.md "Async Job Analysis".
Route::post('/jobs/{jobPosting}/analyses', [JobAnalysisController::class, 'store'])->name('jobs.analyses.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}', [JobAnalysisController::class, 'show'])->name('jobs.analyses.show');

// Read-only polling endpoint for a GenerationAttempt's durable status —
// see App\Http\Controllers\GenerationAttemptController.
Route::get('/generation-attempts/{generationAttempt}', [GenerationAttemptController::class, 'show'])->name('generation-attempts.show');

// "Inspect Application" — dispatches the durable inspection workflow
// (see App\Support\ApplicationInspection\DispatchApplicationInspection)
// and the read-only results page. Never calls the browser worker
// directly — see routes/api.php for the machine-only protocol surface
// this workflow's AgentRun is eventually claimed and reported through.
Route::post('/jobs/{jobPosting}/applications', [ApplicationController::class, 'store'])->name('jobs.applications.store');
Route::get('/applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
Route::get('/applications/{application}/status', [ApplicationInspectionStatusController::class, 'show'])->name('applications.status');

// Read-only worker presence feed for the nav-bar badge and
// "waiting for browser worker" states — see
// App\Support\ApplicationInspection\PresentWorkerAvailability.
Route::get('/browser-worker/status', [BrowserWorkerStatusController::class, 'show'])->name('browser-worker.status');

// Job Match generation is also queued now (GenerateJobMatchJob), same
// pattern as Job Analysis above. AllowLongRunningGeneration is kept on
// this route for this milestone even though the POST request itself no
// longer blocks on the Ollama call — see docs/job-match-generation.md
// "Async Job Match" and this middleware's own docblock.
Route::post('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches', [JobMatchController::class, 'store'])->middleware(AllowLongRunningGeneration::class)->name('jobs.analyses.matches.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}', [JobMatchController::class, 'show'])->name('jobs.analyses.matches.show');

// Resume generation is also queued now (GenerateResumeVariantJob), same
// pattern as Job Analysis/Job Match above — one attempt covers the
// entire Selection -> Wording pipeline. AllowLongRunningGeneration is
// kept on this route for this milestone even though the POST request
// itself no longer blocks on either provider call — see
// docs/resume-variant-generation.md "Async Resume" and this
// middleware's own docblock.
Route::post('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}/resume', [ResumeVariantController::class, 'store'])->middleware(AllowLongRunningGeneration::class)->name('jobs.analyses.matches.resume.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}/resume/{resumeVariant}', [ResumeVariantController::class, 'show'])->name('jobs.analyses.matches.resume.show');

// Deterministic, non-Inertia print/preview surface — the single visual
// source for both browser preview and PDF export. See
// App\Http\Controllers\ResumeVariantPreviewController.
Route::get('/resume-variants/{resumeVariant}/preview', [ResumeVariantPreviewController::class, 'show'])->name('resume-variants.preview');

// PDF export of the same document — see
// App\Http\Controllers\ResumeVariantPdfController.
Route::get('/resume-variants/{resumeVariant}/pdf', [ResumeVariantPdfController::class, 'show'])->name('resume-variants.pdf');
