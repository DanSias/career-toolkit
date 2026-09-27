<?php

use App\Support\JobAnalysis\SegmentJobPostingDescription;

/**
 * App\Support\JobAnalysis\SegmentJobPostingDescription is the
 * foundation of JobAnalysisPromptV4's evidence architecture — its
 * output is what the model cites by id and what GenerateJobAnalysis
 * resolves back to persisted evidence text, so its determinism and
 * exact-text preservation are load-bearing. See
 * docs/job-analysis-generation.md "Async Job Analysis".
 */
function segmenter(): SegmentJobPostingDescription
{
    return new SegmentJobPostingDescription;
}

it('produces the same segment map for the same input every time', function () {
    $description = "First sentence here. Second sentence here.\n\nA new paragraph.";

    $first = segmenter()->segment($description);
    $second = segmenter()->segment($description);

    expect($first)->toBe($second);
});

it('assigns stable, ordered, zero-padded ids in order of appearance', function () {
    $description = "First bullet.\nSecond bullet.\nThird bullet.";

    $segments = segmenter()->segment($description);

    expect(array_keys($segments))->toBe(['S001', 'S002', 'S003'])
        ->and($segments['S001'])->toBe('First bullet.')
        ->and($segments['S002'])->toBe('Second bullet.')
        ->and($segments['S003'])->toBe('Third bullet.');
});

it('assigns unique ids even when segment text repeats', function () {
    $description = "Ship fast.\nShip fast.\nShip fast.";

    $segments = segmenter()->segment($description);

    expect(array_keys($segments))->toBe(['S001', 'S002', 'S003'])
        ->and(array_unique(array_keys($segments)))->toHaveCount(3);
});

it('preserves each segment\'s exact original source text, untouched', function () {
    $description = "We value people who don\u{2019}t give up — ever.";

    $segments = segmenter()->segment($description);

    expect($segments['S001'])->toBe("We value people who don\u{2019}t give up — ever.");
});

it('keeps distinct lines/bullets as separate segments even without a blank line between them', function () {
    $description = "- Minimum 5 years required.\n- Travel up to 25%.";

    $segments = segmenter()->segment($description);

    expect($segments)->toBe([
        'S001' => '- Minimum 5 years required.',
        'S002' => '- Travel up to 25%.',
    ]);
});

it('treats a blank-line-separated paragraph as a segmentation boundary, not a merge point', function () {
    $description = "Paragraph one, one sentence.\n\nParagraph two, one sentence.";

    $segments = segmenter()->segment($description);

    expect($segments)->toBe([
        'S001' => 'Paragraph one, one sentence.',
        'S002' => 'Paragraph two, one sentence.',
    ]);
});

it('splits a single line into multiple sentence-level segments', function () {
    $description = 'Nothing counts as shipped until it runs. Ship it, then check the output.';

    $segments = segmenter()->segment($description);

    expect($segments)->toBe([
        'S001' => 'Nothing counts as shipped until it runs.',
        'S002' => 'Ship it, then check the output.',
    ]);
});

it('does not split on a period that is not followed by a capital letter or digit', function () {
    $description = 'We pay $220k-$250k. base salary, plus equity.';

    $segments = segmenter()->segment($description);

    // "plus" is lowercase after the period, so this stays one segment —
    // deliberately conservative, matching the class's small/deterministic
    // mandate rather than a full sentence-boundary detector.
    expect($segments)->toBe([
        'S001' => 'We pay $220k-$250k. base salary, plus equity.',
    ]);
});

it('does not split immediately after a known abbreviation', function () {
    $description = 'We use modern tooling, e.g. Kubernetes and Terraform. We ship weekly.';

    $segments = segmenter()->segment($description);

    expect($segments)->toBe([
        'S001' => 'We use modern tooling, e.g. Kubernetes and Terraform.',
        'S002' => 'We ship weekly.',
    ]);
});

it('preserves typographic quotes and dashes exactly, without any normalization', function () {
    $description = "TRM\u{2019}s values include being \u{201C}radically transparent\u{201D} — always.";

    $segments = segmenter()->segment($description);

    expect($segments['S001'])->toBe("TRM\u{2019}s values include being \u{201C}radically transparent\u{201D} — always.");
});

it('preserves bullet markers and leading punctuation as part of the segment text', function () {
    $description = '- ERP experience is not required.';

    $segments = segmenter()->segment($description);

    expect($segments['S001'])->toBe('- ERP experience is not required.');
});

it('skips blank and whitespace-only lines without creating empty segments', function () {
    $description = "First line.\n\n   \n\nSecond line.";

    $segments = segmenter()->segment($description);

    expect($segments)->toBe([
        'S001' => 'First line.',
        'S002' => 'Second line.',
    ]);
});

it('trims leading/trailing whitespace from a segment without altering internal characters', function () {
    $description = '   Padded line with   internal spacing preserved.   ';

    $segments = segmenter()->segment($description);

    expect($segments)->toBe([
        'S001' => 'Padded line with   internal spacing preserved.',
    ]);
});

it('returns an empty segment map for an empty description', function () {
    expect(segmenter()->segment(''))->toBe([]);
});

it('returns an empty segment map for a whitespace-only description', function () {
    expect(segmenter()->segment("   \n\n   \n"))->toBe([]);
});

it('handles a single short line with no sentence-ending punctuation as one segment', function () {
    expect(segmenter()->segment('Requirements'))->toBe(['S001' => 'Requirements']);
});
