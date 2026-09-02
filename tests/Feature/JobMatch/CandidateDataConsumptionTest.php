<?php

/**
 * Unlike JobAnalysis generation (see
 * tests/Feature/JobAnalysis/CandidateIndependenceTest.php), the JobMatch
 * generation layer is DELIBERATELY not candidate-independent — matching
 * candidate data against a JobAnalysis is its entire purpose. This test
 * confirms that intentional dependency actually exists, as a structural
 * guard against the pipeline silently regressing into a shape that
 * never actually reaches CareerFact/Education data (which would make
 * every match a silent no-op rather than a real comparison).
 */
$generationLayerFiles = [
    'app/Contracts/GeneratesJobMatch.php',
    'app/Support/JobMatch/GenerateJobMatch.php',
    'app/Support/JobMatch/CandidatePayloadBuilder.php',
    'app/Support/JobMatch/JobMatchResponseValidator.php',
];

it('confirms the JobMatch generation layer genuinely references CareerFact and Education', function () use ($generationLayerFiles) {
    $combined = '';

    foreach ($generationLayerFiles as $relativePath) {
        $path = base_path($relativePath);
        expect($path)->toBeFile();

        $contents = file_get_contents($path);
        expect($contents)->not->toBeFalse();

        $combined .= $contents;
    }

    expect($combined)->toContain('Models\\CareerFact')
        ->and($combined)->toContain('Models\\Education');
});

it('confirms GenerateJobMatch actually persists CareerFactMatch and EducationMatch rows, not just references the classes', function () {
    $contents = file_get_contents(base_path('app/Support/JobMatch/GenerateJobMatch.php'));

    expect($contents)->toContain('careerFactMatches()->create')
        ->and($contents)->toContain('educationMatches()->create');
});
