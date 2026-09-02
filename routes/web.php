<?php

use App\Http\Controllers\CareerDataController;
use App\Http\Controllers\JobPostingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CareerDataController::class, 'index'])->name('career-data.index');
Route::get('/career-data/facts/{careerFact}', [CareerDataController::class, 'showFact'])->name('career-data.facts.show');

Route::get('/jobs', [JobPostingController::class, 'index'])->name('jobs.index');
Route::get('/jobs/create', [JobPostingController::class, 'create'])->name('jobs.create');
Route::post('/jobs', [JobPostingController::class, 'store'])->name('jobs.store');
Route::get('/jobs/{jobPosting}', [JobPostingController::class, 'show'])->name('jobs.show');
