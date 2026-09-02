<?php

/**
 * Extends the candidate-independence guarantee already enforced at the
 * model layer (see tests/Feature/Domain/JobAnalysisTest.php) up to the
 * generation-application layer: nothing that builds, calls, validates,
 * or persists a JobAnalysis may reference CareerFact, Skill, Project,
 * Employer, or Role. A simple source-scan is deliberately used instead
 * of a static-analysis framework — it's the smallest check that catches
 * an accidental `use App\Models\CareerFact;` or similar.
 */
$generationLayerFiles = [
    'app/Contracts/GeneratesJobAnalysis.php',
    'app/Support/JobAnalysis/GenerateJobAnalysis.php',
    'app/Support/JobAnalysis/JobAnalysisResponseValidator.php',
    'app/Support/JobAnalysis/EvidenceExcerptVerifier.php',
    'app/Support/JobAnalysis/JobAnalysisDraft.php',
    'app/Support/JobAnalysis/JobAnalysisFindingDraft.php',
    'app/Support/JobAnalysis/JobAnalysisFindingEvidenceDraft.php',
    'app/Support/JobAnalysis/JobAnalysisProviderResponse.php',
    'app/Support/JobAnalysis/Prompts/JobAnalysisPromptV1.php',
    'app/Support/JobAnalysis/Prompts/JobAnalysisPromptV2.php',
    'app/Support/JobAnalysis/Providers/OpenAIJobAnalysisClient.php',
    'app/Http/Controllers/JobAnalysisController.php',
];

$forbiddenModels = ['CareerFact', 'Skill', 'Project', 'Employer', 'Role'];

it('keeps the JobAnalysis generation layer free of any candidate-side model reference', function () use ($generationLayerFiles, $forbiddenModels) {
    foreach ($generationLayerFiles as $relativePath) {
        $path = base_path($relativePath);

        expect($path)->toBeFile();

        $contents = file_get_contents($path);
        expect($contents)->not->toBeFalse();

        foreach ($forbiddenModels as $model) {
            expect($contents)->not->toContain("Models\\{$model}");
        }
    }
});
