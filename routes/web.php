<?php

use App\Http\Controllers\CareerDataController;
use App\Http\Controllers\JobAnalysisController;
use App\Http\Controllers\JobMatchController;
use App\Http\Controllers\JobPostingController;
use App\Http\Controllers\ResumeVariantController;
use App\Http\Controllers\ResumeVariantPdfController;
use App\Http\Controllers\ResumeVariantPreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CareerDataController::class, 'index'])->name('career-data.index');
Route::get('/career-data/facts/{careerFact}', [CareerDataController::class, 'showFact'])->name('career-data.facts.show');

Route::get('/jobs', [JobPostingController::class, 'index'])->name('jobs.index');
Route::get('/jobs/create', [JobPostingController::class, 'create'])->name('jobs.create');
Route::post('/jobs', [JobPostingController::class, 'store'])->name('jobs.store');
Route::get('/jobs/{jobPosting}', [JobPostingController::class, 'show'])->name('jobs.show');

Route::post('/jobs/{jobPosting}/analyses', [JobAnalysisController::class, 'store'])->name('jobs.analyses.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}', [JobAnalysisController::class, 'show'])->name('jobs.analyses.show');

Route::post('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches', [JobMatchController::class, 'store'])->name('jobs.analyses.matches.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}', [JobMatchController::class, 'show'])->name('jobs.analyses.matches.show');

Route::post('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}/resume', [ResumeVariantController::class, 'store'])->name('jobs.analyses.matches.resume.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}/resume/{resumeVariant}', [ResumeVariantController::class, 'show'])->name('jobs.analyses.matches.resume.show');

// Deterministic, non-Inertia print/preview surface — the single visual
// source for both browser preview and PDF export. See
// App\Http\Controllers\ResumeVariantPreviewController.
Route::get('/resume-variants/{resumeVariant}/preview', [ResumeVariantPreviewController::class, 'show'])->name('resume-variants.preview');

// PDF export of the same document — see
// App\Http\Controllers\ResumeVariantPdfController.
Route::get('/resume-variants/{resumeVariant}/pdf', [ResumeVariantPdfController::class, 'show'])->name('resume-variants.pdf');
