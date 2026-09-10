<?php

use App\Exceptions\InvalidResumeVariantResponseException;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;

/**
 * Focused coverage for ResumeWordingResponseValidator's deterministic
 * per-bullet word-count ceiling (see BULLET_WORD_COUNT_CEILING) — the
 * structural check added to catch clearly excessive output (like the
 * ~46-word run-on bullets from the first live Pearly evaluation)
 * without making normal technical prose brittle. The prompt's own
 * ~20-28 word target/~32-word soft maximum are quality guidance only
 * and are deliberately NOT asserted here — only the generous hard
 * ceiling is a deterministic gate.
 *
 * Every other validator behavior (completeness, denylist, guardrails)
 * already has coverage via the integration tests in
 * GenerateResumeVariantTest.php and friends — this file exists only
 * to exercise the word-count ceiling boundary directly, which is hard
 * to hit precisely through the full pipeline's FakeResumeWordingProvider
 * fixtures.
 */
function wordsOfLength(int $count): string
{
    return implode(' ', array_fill(0, $count, 'word'));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function minimalValidWordingContent(array $overrides = []): array
{
    return array_merge([
        'summary' => 'A concise professional summary.',
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => wordsOfLength(25)],
            ]],
        ],
        'selected_projects' => [],
    ], $overrides);
}

function validateWordingContent(array $structuredContent): array
{
    return (new ResumeWordingResponseValidator)->validate(
        $structuredContent,
        validRoleIds: [1],
        expectedBulletGroupIndexesByRole: [1 => [0]],
        validSelectedProjectIds: [],
        denylistTerms: [],
        careerFactKeysByLocation: [],
        guardrailByFactKey: [],
    );
}

it('accepts an Experience bullet at exactly the 40-word ceiling', function () {
    $content = minimalValidWordingContent([
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => wordsOfLength(40)],
            ]],
        ],
    ]);

    $validated = validateWordingContent($content);

    expect($validated)->toBeArray();
});

it('rejects an Experience bullet one word over the 40-word ceiling', function () {
    $content = minimalValidWordingContent([
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => wordsOfLength(41)],
            ]],
        ],
    ]);

    expect(fn () => validateWordingContent($content))
        ->toThrow(InvalidResumeVariantResponseException::class, 'over the 40-word structural ceiling');
});

it('accepts a normal ~25-word Experience bullet without complaint', function () {
    $content = minimalValidWordingContent();

    $validated = validateWordingContent($content);

    expect($validated)->toBeArray();
});

it('rejects a Selected Project bullet one word over the 40-word ceiling', function () {
    $content = minimalValidWordingContent([
        'selected_projects' => [
            ['project_id' => 9, 'text' => wordsOfLength(41)],
        ],
    ]);

    expect(fn () => (new ResumeWordingResponseValidator)->validate(
        $content,
        validRoleIds: [1],
        expectedBulletGroupIndexesByRole: [1 => [0]],
        validSelectedProjectIds: [9],
        denylistTerms: [],
        careerFactKeysByLocation: [],
        guardrailByFactKey: [],
    ))->toThrow(InvalidResumeVariantResponseException::class, 'over the 40-word structural ceiling');
});

it('accepts a Selected Project bullet at exactly the 40-word ceiling', function () {
    $content = minimalValidWordingContent([
        'selected_projects' => [
            ['project_id' => 9, 'text' => wordsOfLength(40)],
        ],
    ]);

    $validated = (new ResumeWordingResponseValidator)->validate(
        $content,
        validRoleIds: [1],
        expectedBulletGroupIndexesByRole: [1 => [0]],
        validSelectedProjectIds: [9],
        denylistTerms: [],
        careerFactKeysByLocation: [],
        guardrailByFactKey: [],
    );

    expect($validated)->toBeArray();
});

it('does not persist anything and simply throws on a word-count ceiling violation — validation failure carries the raw content as context', function () {
    $content = minimalValidWordingContent([
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => wordsOfLength(46)],
            ]],
        ],
    ]);

    try {
        validateWordingContent($content);
        $this->fail('Expected InvalidResumeVariantResponseException to be thrown.');
    } catch (InvalidResumeVariantResponseException $e) {
        expect($e->context)->toBe($content);
    }
});
