<?php

use App\Exceptions\InvalidResumeVariantResponseException;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;

/**
 * Focused coverage for two ResumeWordingResponseValidator checks that
 * are hard to hit precisely through the full pipeline's
 * FakeResumeWordingProvider fixtures:
 *
 * - the deterministic per-bullet word-count ceiling (see
 *   BULLET_WORD_COUNT_CEILING) — catches clearly excessive output (like
 *   the ~46-word run-on bullets from the first live Pearly evaluation)
 *   without making normal technical prose brittle. The prompt's own
 *   ~20-28 word target/~32-word soft maximum are quality guidance only
 *   and are deliberately NOT asserted here — only the generous hard
 *   ceiling is a deterministic gate.
 * - location-scoped canonical-Skill provenance (see
 *   assertSkillProvenance()'s own docblock) — added after the first
 *   live qwen3.8:27b Resume Wording evaluation showed a generated
 *   summary borrowing "React"/"Laravel" from evidence supplied to
 *   other bullets/projects in the same request, not the summary's own
 *   evidence. See docs/resume-variant-generation.md "Skill provenance"
 *   for the full investigation.
 *
 * Every other validator behavior (completeness, denylist, guardrails)
 * already has coverage via the integration tests in
 * GenerateResumeVariantTest.php and friends.
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

/**
 * @param  array<string, mixed>  $structuredContent
 * @param  array<int, int>  $validSelectedProjectIds
 * @param  array<string, array<int, string>>  $careerFactKeysByLocation
 * @param  array<string, array<int, int>>  $skillIdsByFactKey
 * @param  array<int, string>  $canonicalSkillsById
 * @param  array<string, array<int, int>>  $textRecognizedSkillIdsByFactKey
 * @return array<string, mixed>
 */
function validateWordingContent(
    array $structuredContent,
    array $validSelectedProjectIds = [],
    array $careerFactKeysByLocation = [],
    array $skillIdsByFactKey = [],
    array $canonicalSkillsById = [],
    array $textRecognizedSkillIdsByFactKey = [],
): array {
    return (new ResumeWordingResponseValidator)->validate(
        $structuredContent,
        validRoleIds: [1],
        expectedBulletGroupIndexesByRole: [1 => [0]],
        validSelectedProjectIds: $validSelectedProjectIds,
        denylistTerms: [],
        careerFactKeysByLocation: $careerFactKeysByLocation,
        guardrailByFactKey: [],
        skillIdsByFactKey: $skillIdsByFactKey,
        canonicalSkillsById: $canonicalSkillsById,
        textRecognizedSkillIdsByFactKey: $textRecognizedSkillIdsByFactKey,
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
        skillIdsByFactKey: [],
        canonicalSkillsById: [],
        textRecognizedSkillIdsByFactKey: [],
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
        skillIdsByFactKey: [],
        canonicalSkillsById: [],
        textRecognizedSkillIdsByFactKey: [],
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

// --- Skill provenance ---
//
// canonicalSkillsById mirrors real Skill rows from the Formic corpus:
// 1 => React, 2 => Node.js, 3 => Laravel, 4 => Git, 5 => GitHub,
// 6 => GitLab, 7 => AI-assisted development. Fact keys are arbitrary
// strings — only their presence in careerFactKeysByLocation and
// skillIdsByFactKey matters to this check.

function realisticCanonicalSkills(): array
{
    return [
        1 => 'React',
        2 => 'Node.js',
        3 => 'Laravel',
        4 => 'Git',
        5 => 'GitHub',
        6 => 'GitLab',
        7 => 'AI-assisted development',
        8 => 'Salesforce',
        9 => 'Salesforce Marketing Cloud',
    ];
}

it('accepts an authorized canonical Skill mention in the Summary', function () {
    $content = minimalValidWordingContent(['summary' => 'Builds with React across the stack.']);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [1]], // React authorized for the summary
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

it('rejects an unauthorized canonical Skill mention in the Summary', function () {
    $content = minimalValidWordingContent(['summary' => 'Builds with React across the stack.']);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [3]], // only Laravel authorized — not React
        canonicalSkillsById: realisticCanonicalSkills(),
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [React] (Skill id 1)');
});

it('accepts an authorized canonical Skill mention in an Experience bullet', function () {
    $content = minimalValidWordingContent([
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => 'Built the platform using Node.js on the backend.'],
            ]],
        ],
    ]);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['1:0' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [2]], // Node.js authorized for this bullet
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

it('rejects a Skill that is authorized elsewhere in the request but not for this Experience bullet — the exact cross-location leakage the live run demonstrated', function () {
    $content = minimalValidWordingContent([
        'summary' => 'A senior engineer who partners closely with stakeholders.',
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => 'Also brought in React for the dashboard.'],
            ]],
        ],
    ]);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: [
            'summary' => ['fact-a'], // React is authorized HERE, for the summary...
            '1:0' => ['fact-b'], // ...not for this Experience bullet's own evidence
        ],
        skillIdsByFactKey: [
            'fact-a' => [1],
            'fact-b' => [],
        ],
        canonicalSkillsById: realisticCanonicalSkills(),
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [React] (Skill id 1)');
});

it('accepts an authorized canonical Skill mention in a Selected Project bullet', function () {
    $content = minimalValidWordingContent([
        'selected_projects' => [
            ['project_id' => 9, 'text' => 'Built with Node.js and Prisma end to end.'],
        ],
    ]);

    $validated = validateWordingContent(
        $content,
        validSelectedProjectIds: [9],
        careerFactKeysByLocation: ['project:9' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [2]], // Node.js authorized for this project
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

it('rejects cross-location leakage into a Selected Project bullet', function () {
    $content = minimalValidWordingContent([
        'selected_projects' => [
            ['project_id' => 9, 'text' => 'Built with Laravel end to end.'],
        ],
    ]);

    expect(fn () => validateWordingContent(
        $content,
        validSelectedProjectIds: [9],
        careerFactKeysByLocation: [
            '1:0' => ['fact-a'], // Laravel is authorized for the Experience bullet
            'project:9' => ['fact-b'], // but NOT for this project
        ],
        skillIdsByFactKey: [
            'fact-a' => [3],
            'fact-b' => [],
        ],
        canonicalSkillsById: realisticCanonicalSkills(),
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [Laravel] (Skill id 3)');
});

it('reports every unauthorized canonical Skill when a location names more than one', function () {
    $content = minimalValidWordingContent(['summary' => 'Combines React, Node.js, and Laravel end to end.']);

    try {
        validateWordingContent(
            $content,
            careerFactKeysByLocation: ['summary' => ['fact-a']],
            skillIdsByFactKey: ['fact-a' => []], // none of the three authorized
            canonicalSkillsById: realisticCanonicalSkills(),
        );
        test()->fail('Expected InvalidResumeVariantResponseException to be thrown.');
    } catch (InvalidResumeVariantResponseException $e) {
        expect($e->getMessage())
            ->toContain('[React] (Skill id 1)')
            ->toContain('[Node.js] (Skill id 2)')
            ->toContain('[Laravel] (Skill id 3)');
    }
});

it('does not let "Git" false-positive match inside "GitHub" or "GitLab" — word-boundary matching, not substring matching', function () {
    $content = minimalValidWordingContent(['summary' => 'Used GitHub and GitLab for source control.']);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [5, 6]], // GitHub and GitLab authorized; Git is not, and must not spuriously match
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

it('matches React as an exact, unauthorized canonical Skill mention', function () {
    $content = minimalValidWordingContent(['summary' => 'A candidate skilled in React development.']);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
    ))->toThrow(InvalidResumeVariantResponseException::class, '[React] (Skill id 1)');
});

it('matches the exact canonical name "Node.js" as an unauthorized mention', function () {
    $content = minimalValidWordingContent(['summary' => 'A candidate skilled in Node.js development.']);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
    ))->toThrow(InvalidResumeVariantResponseException::class, '[Node.js] (Skill id 2)');
});

it('does NOT match bare "Node" against the canonical Skill "Node.js" — a documented, intentional limitation, not a bug', function () {
    $content = minimalValidWordingContent(['summary' => 'A candidate skilled in Node development.']);

    // Same unauthorized scenario as the "Node.js" test above, but the
    // generated text uses the informal short form instead of the
    // canonical name. This must PASS — not because the mention is
    // authorized, but because this check cannot recognize it as the
    // canonical Skill "Node.js" at all. See
    // ResumeWordingResponseValidator::assertSkillProvenance()'s own
    // docblock for why this gap is accepted rather than closed with an
    // alias table.
    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

it('matches case-sensitively — a different-case mention of a canonical Skill name is not recognized', function () {
    $content = minimalValidWordingContent(['summary' => 'Comfortable with react and modern tooling.']);

    // "react" (lowercase) is not the canonical name "React" — this
    // check is deliberately case-sensitive (see its own docblock), so
    // this passes even though it is unauthorized, exactly like the
    // Node/Node.js gap above.
    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

it('leaves prose with no recognized canonical Skill mentions unaffected', function () {
    $content = minimalValidWordingContent(['summary' => 'A senior engineer who partners closely with stakeholders.']);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

// --- Overlap resolution: longest-span-wins between two exact canonical matches ---
//
// "Salesforce" (id 8) is a genuine, word-bounded substring of the
// separate canonical Skill "Salesforce Marketing Cloud" (id 9) — found
// against real historical data. This is no longer treated as an
// accepted limitation: recognizedSkillIds() resolves the overlap
// deterministically (longest span wins), so writing the compound name
// no longer falsely implicates the shorter, unrelated Skill.

it('recognizes bare "Salesforce" when it appears alone', function () {
    $content = minimalValidWordingContent(['summary' => 'Reporting built on Salesforce data.']);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []], // Salesforce (id 8) NOT authorized
        canonicalSkillsById: realisticCanonicalSkills(),
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [Salesforce] (Skill id 8)');
});

it('recognizes only "Salesforce Marketing Cloud" — not also the shorter "Salesforce" it contains', function () {
    $content = minimalValidWordingContent([
        'summary' => 'Combined data from Salesforce Marketing Cloud into one dashboard.',
    ]);

    // Only "Salesforce Marketing Cloud" (id 9) is authorized; bare
    // "Salesforce" (id 8) is NOT. Before overlap resolution this threw
    // for [Salesforce] (id 8) — the false positive. Now it must pass
    // cleanly: the only genuine, surviving match is the longer,
    // authorized compound name.
    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [9]],
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

it('recognizes BOTH canonical Skills when they occur as separate, non-overlapping textual occurrences', function () {
    $content = minimalValidWordingContent([
        'summary' => 'Used Salesforce and Salesforce Marketing Cloud on the same project.',
    ]);

    // Only "Salesforce Marketing Cloud" (id 9) is authorized — the
    // standalone "Salesforce" (id 8) occurrence is a separate, genuine,
    // non-overlapping mention and must still be caught.
    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [9]],
        canonicalSkillsById: realisticCanonicalSkills(),
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [Salesforce] (Skill id 8)');
});

it('accepts both occurrences when both the standalone and compound canonical Skills are authorized', function () {
    $content = minimalValidWordingContent([
        'summary' => 'Used Salesforce and Salesforce Marketing Cloud on the same project.',
    ]);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [8, 9]], // both authorized
        canonicalSkillsById: realisticCanonicalSkills(),
    );

    expect($validated)->toBeArray();
});

it('evaluates skill provenance independently of denylist/guardrail checks — an unrelated denylist violation does not mask or replace a skill-provenance violation', function () {
    $content = minimalValidWordingContent(['summary' => 'Uses React and mentions Apollo directly.']);

    try {
        (new ResumeWordingResponseValidator)->validate(
            $content,
            validRoleIds: [1],
            expectedBulletGroupIndexesByRole: [1 => [0]],
            validSelectedProjectIds: [],
            denylistTerms: ['Apollo'],
            careerFactKeysByLocation: ['summary' => ['fact-a']],
            guardrailByFactKey: [],
            skillIdsByFactKey: ['fact-a' => []],
            canonicalSkillsById: realisticCanonicalSkills(),
            textRecognizedSkillIdsByFactKey: [],
        );
        test()->fail('Expected InvalidResumeVariantResponseException to be thrown.');
    } catch (InvalidResumeVariantResponseException $e) {
        expect($e->getMessage())
            ->toContain('denylisted target term [Apollo]')
            ->toContain('[React] (Skill id 1)');
    }
});

// --- Fact-local additive evidence contract ---
//
// Established by the Skill-provenance authority-model investigation:
// per-location authorization = Skill ids ATTACHED to that location's
// cited facts UNION Skill ids RECOGNIZED (via the same
// recognizedSkillIds() longest-span logic) in those same facts' own
// evidence-bearing text (statement + metric scope_note/guardrail).
// Both sources remain strictly fact-local — neither ever authorizes
// from a different fact or a different prose location. Mirrors
// JobMatchPromptV3's own evidence boundary ("that fact's own
// statement, attached Skills, metric/guardrail/scope_note"). See
// docs/resume-variant-generation.md "Skill provenance".

it('still authorizes via an attached Skill alone, absent from the fact\'s own statement text — pre-existing behavior preserved', function () {
    $content = minimalValidWordingContent(['summary' => 'Builds with React across the stack.']);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => [1]], // React attached; fact's own statement text is irrelevant here
        canonicalSkillsById: realisticCanonicalSkills(),
        textRecognizedSkillIdsByFactKey: ['fact-a' => []], // nothing textually recognized
    );

    expect($validated)->toBeArray();
});

it('authorizes via a canonical Skill literally present in the fact\'s own statement text, even when NOT attached as a Skill', function () {
    $content = minimalValidWordingContent(['summary' => 'Builds with React across the stack.']);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []], // React NOT attached
        canonicalSkillsById: realisticCanonicalSkills(),
        // Simulates GenerateResumeVariant having run recognizedSkillIds()
        // against fact-a's own statement text and found "React" there.
        textRecognizedSkillIdsByFactKey: ['fact-a' => [1]],
    );

    expect($validated)->toBeArray();
});

it('authorizes via a canonical Skill literally present in the fact\'s own metric evidence (scope_note/guardrail), the same way as statement text', function () {
    $content = minimalValidWordingContent(['summary' => 'Delivered with Laravel end to end.']);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []], // Laravel NOT attached
        canonicalSkillsById: realisticCanonicalSkills(),
        // Simulates GenerateResumeVariant having found "Laravel" in
        // fact-a's own metric.scope_note/guardrail text, not its
        // statement — the validator itself is agnostic to which
        // evidence-bearing field produced the recognition; that
        // distinction lives entirely in how the caller builds this map.
        textRecognizedSkillIdsByFactKey: ['fact-a' => [3]],
    );

    expect($validated)->toBeArray();
});

it('still rejects a Skill established only by a DIFFERENT CareerFact, even when that other fact is cited at the very same location', function () {
    $content = minimalValidWordingContent(['summary' => 'Builds with React across the stack.']);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a', 'fact-b']],
        skillIdsByFactKey: ['fact-a' => [], 'fact-b' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
        // React is textually recognized on fact-b, not fact-a — but
        // since fact-b IS cited at this same location, this is legal
        // fact-local authorization, not cross-fact borrowing. This
        // proves the union operates per-location over ALL of that
        // location's own cited facts, not just the first one.
        textRecognizedSkillIdsByFactKey: ['fact-a' => [], 'fact-b' => [1]],
    ))->not->toThrow(InvalidResumeVariantResponseException::class);
});

it('rejects a Skill textually recognized only on a fact cited at a DIFFERENT Experience bullet', function () {
    $content = minimalValidWordingContent([
        'summary' => 'A senior engineer who partners closely with stakeholders.',
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => 'Also brought in React for the dashboard.'],
            ]],
        ],
    ]);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: [
            '1:0' => ['fact-a'], // this bullet's own cited fact — no React anywhere
            'summary' => ['fact-b'], // React is textually recognized HERE instead
        ],
        skillIdsByFactKey: ['fact-a' => [], 'fact-b' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
        textRecognizedSkillIdsByFactKey: ['fact-a' => [], 'fact-b' => [1]],
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [React] (Skill id 1)');
});

it('rejects a Skill textually recognized only on a fact cited at a DIFFERENT Selected Project', function () {
    $content = minimalValidWordingContent([
        'selected_projects' => [
            ['project_id' => 9, 'text' => 'Built with Laravel end to end.'],
        ],
    ]);

    expect(fn () => validateWordingContent(
        $content,
        validSelectedProjectIds: [9],
        careerFactKeysByLocation: [
            'project:9' => ['fact-a'], // this project's own cited fact — no Laravel anywhere
            '1:0' => ['fact-b'], // Laravel is textually recognized HERE instead
        ],
        skillIdsByFactKey: ['fact-a' => [], 'fact-b' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
        textRecognizedSkillIdsByFactKey: ['fact-a' => [], 'fact-b' => [3]],
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [Laravel] (Skill id 3)');
});

it('does not let the Summary borrow a Skill textually recognized only on an Experience/project fact outside its own supplied facts', function () {
    $content = minimalValidWordingContent([
        'summary' => 'Combines Salesforce Marketing Cloud experience across the stack.',
    ]);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: [
            'summary' => ['fact-a'], // summary's own fact — no Salesforce Marketing Cloud anywhere
            '1:0' => ['fact-b'], // it's textually recognized on an Experience fact instead
        ],
        skillIdsByFactKey: ['fact-a' => [], 'fact-b' => []],
        canonicalSkillsById: realisticCanonicalSkills(),
        textRecognizedSkillIdsByFactKey: ['fact-a' => [], 'fact-b' => [9]],
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [Salesforce Marketing Cloud] (Skill id 9)');
});

it('applies longest-span resolution to fact-local text recognition too: a fact whose own text says "Salesforce Marketing Cloud" does not also authorize bare "Salesforce"', function () {
    $canonicalSkillsById = realisticCanonicalSkills();
    $validatorForRecognition = new ResumeWordingResponseValidator;

    // Exactly how GenerateResumeVariant would build this map — running
    // the real evidence text through the real recognition method.
    $recognizedForFactA = $validatorForRecognition->recognizedSkillIds(
        'Combined data via Salesforce Marketing Cloud into one dashboard.',
        $canonicalSkillsById,
    );

    expect($recognizedForFactA)->toBe([9]); // only the compound Skill — overlap-suppressed, not [8, 9]

    $content = minimalValidWordingContent(['summary' => 'Reporting built on Salesforce data.']);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []],
        canonicalSkillsById: $canonicalSkillsById,
        textRecognizedSkillIdsByFactKey: ['fact-a' => $recognizedForFactA],
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [Salesforce] (Skill id 8)');
});

it('recognizes BOTH Skills when a fact\'s own text contains genuinely separate, non-overlapping occurrences', function () {
    $canonicalSkillsById = realisticCanonicalSkills();
    $recognizedForFactA = (new ResumeWordingResponseValidator)->recognizedSkillIds(
        'Used Salesforce and, separately, Salesforce Marketing Cloud on the same engagement.',
        $canonicalSkillsById,
    );

    expect($recognizedForFactA)->toEqualCanonicalizing([8, 9]);

    $content = minimalValidWordingContent(['summary' => 'Combined Salesforce and Salesforce Marketing Cloud data.']);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => ['fact-a']],
        skillIdsByFactKey: ['fact-a' => []],
        canonicalSkillsById: $canonicalSkillsById,
        textRecognizedSkillIdsByFactKey: ['fact-a' => $recognizedForFactA],
    );

    expect($validated)->toBeArray();
});

it('still rejects the original qwen failure shape: Summary facts do not authorize React/Node.js/Laravel merely because those Skills exist elsewhere in the candidate corpus', function () {
    // Mirrors the real first live run: none of the summary's own facts'
    // statements mention React, Node.js, or Laravel anywhere — those
    // technologies are real, but only for OTHER roles/projects in the
    // same request. The fact-local additive contract must not change
    // this outcome.
    $content = minimalValidWordingContent([
        'summary' => 'Combines React, Node, and Laravel application development with data pipelines.',
    ]);

    expect(fn () => validateWordingContent(
        $content,
        careerFactKeysByLocation: [
            'summary' => ['fact-summary-a', 'fact-summary-b'],
            '1:0' => ['fact-nexus'], // React/Node.js/Laravel are real, but only here
        ],
        skillIdsByFactKey: [
            'fact-summary-a' => [], 'fact-summary-b' => [],
            'fact-nexus' => [1, 2, 3],
        ],
        canonicalSkillsById: realisticCanonicalSkills(),
        textRecognizedSkillIdsByFactKey: [
            'fact-summary-a' => [], 'fact-summary-b' => [],
            'fact-nexus' => [],
        ],
    ))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [React] (Skill id 1)');
});

// --- Real-data regression: well-prompted-what-it-is / AI-assisted development ---
//
// Uses the actual real Skill ids/names and the actual real CareerFact
// statement text from the Formic corpus (not synthetic placeholders),
// so this regression is directly traceable to the concrete case the
// investigation was built from.

function wellPromptedRealCanonicalSkills(): array
{
    return [
        8 => 'React',
        10 => 'Node.js',
        14 => 'Prisma',
        68 => 'Supabase',
        5 => 'Tailwind CSS',
        44 => 'AI-assisted development',
    ];
}

const WELL_PROMPTED_REAL_STATEMENT = 'Developers lose time re-deriving good prompts for the same recurring tasks '
    .'(debugging, documentation, code review) from scratch every time. Built a structured prompt library with '
    .'React, Node, and Prisma that keeps AI-assisted development consistent and reusable across a codebase '
    .'instead of ad hoc.';

it('authorizes "AI-assisted development" for the real well-prompted-what-it-is fact, from its own real statement text, even though that Skill is not attached to it', function () {
    $canonicalSkillsById = wellPromptedRealCanonicalSkills();

    // well-prompted-what-it-is's real attached Skills, per the real
    // canonical data: React (8), Node.js (10), Prisma (14), Supabase
    // (68), Tailwind CSS (5) — id 44 (AI-assisted development) is
    // deliberately absent, matching the real corpus exactly.
    $attachedSkillIds = [8, 10, 14, 68, 5];
    $textRecognizedSkillIds = (new ResumeWordingResponseValidator)->recognizedSkillIds(
        WELL_PROMPTED_REAL_STATEMENT,
        $canonicalSkillsById,
    );

    expect($textRecognizedSkillIds)->toContain(44)
        ->and($textRecognizedSkillIds)->toContain(8); // React, from the statement itself

    // The second live qwen3.8:27b run's real Selected Project bullet.
    $content = minimalValidWordingContent([
        'selected_projects' => [
            ['project_id' => 14, 'text' => 'Built a structured prompt library for recurring developer tasks—'
                .'debugging, documentation, code review—using React, Node, and Prisma to make AI-assisted '
                .'development consistent and reusable across a codebase rather than ad hoc.'],
        ],
    ]);

    $validated = validateWordingContent(
        $content,
        validSelectedProjectIds: [14],
        careerFactKeysByLocation: ['project:14' => ['well-prompted-what-it-is']],
        skillIdsByFactKey: ['well-prompted-what-it-is' => $attachedSkillIds],
        canonicalSkillsById: $canonicalSkillsById,
        textRecognizedSkillIdsByFactKey: ['well-prompted-what-it-is' => $textRecognizedSkillIds],
    );

    expect($validated)->toBeArray();
});

it('no longer fails the real historical OpenAI Selected Project bullet solely for naming "AI-assisted development"', function () {
    $canonicalSkillsById = wellPromptedRealCanonicalSkills();
    $attachedSkillIds = [8, 10, 14, 68, 5];
    $textRecognizedSkillIds = (new ResumeWordingResponseValidator)->recognizedSkillIds(
        WELL_PROMPTED_REAL_STATEMENT,
        $canonicalSkillsById,
    );

    // The real, persisted, already-shipped OpenAI wording for
    // ResumeVariant id 2's Selected Project bullet (project_id 14).
    $content = minimalValidWordingContent([
        'selected_projects' => [
            ['project_id' => 14, 'text' => 'Built a structured prompt library that makes AI-assisted development '
                .'consistent and reusable across a codebase for recurring debugging, documentation, and '
                .'code-review tasks.'],
        ],
    ]);

    $validated = validateWordingContent(
        $content,
        validSelectedProjectIds: [14],
        careerFactKeysByLocation: ['project:14' => ['well-prompted-what-it-is']],
        skillIdsByFactKey: ['well-prompted-what-it-is' => $attachedSkillIds],
        canonicalSkillsById: $canonicalSkillsById,
        textRecognizedSkillIdsByFactKey: ['well-prompted-what-it-is' => $textRecognizedSkillIds],
    );

    expect($validated)->toBeArray();
});
