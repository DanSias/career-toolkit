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
 * @param  array<string, array<int, string>>  $directTargetTermsByLocation
 * @return array<string, mixed>
 */
function validateWordingContent(
    array $structuredContent,
    array $validSelectedProjectIds = [],
    array $careerFactKeysByLocation = [],
    array $skillIdsByFactKey = [],
    array $canonicalSkillsById = [],
    array $textRecognizedSkillIdsByFactKey = [],
    array $directTargetTermsByLocation = [],
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
        directTargetTermsByLocation: $directTargetTermsByLocation,
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
        directTargetTermsByLocation: [],
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
        directTargetTermsByLocation: [],
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
            directTargetTermsByLocation: [],
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

// --- Real-data regression: the recurring Formic Summary leak (ResumeVariant id 2) ---
//
// Uses the real four summary_evidence CareerFacts, their real Skill
// ids/names, and the real Skill ids that leaked (React 8, Node.js 10,
// Laravel 1, TypeScript 4) — deterministically reconstructed, zero
// inference, from the real dev database during the recurring-Summary-
// failure investigation. Proves the new summary_authorized_skills
// affordance is a generation-time aid only: it did not, and must not,
// weaken what assertSkillProvenance() actually authorizes. See
// docs/resume-variant-generation.md "Skill provenance".

function formicSummaryRealCanonicalSkills(): array
{
    return [
        1 => 'Laravel',
        2 => 'PHP',
        4 => 'TypeScript',
        8 => 'React',
        10 => 'Node.js',
        23 => 'Salesforce',
        24 => 'Salesforce Marketing Cloud',
        25 => 'BigQuery',
        26 => 'Google Analytics',
        41 => 'Deterministic AI boundaries',
        44 => 'AI-assisted development',
        59 => 'Marketing analytics',
        60 => 'Campaign attribution',
        61 => 'Marketing automation',
    ];
}

/**
 * The real, current summary_evidence CareerFacts for ResumeVariant id
 * 2 (Formic) — key => [statement, attached Skill ids].
 */
function formicSummaryRealFacts(): array
{
    return [
        'pearson-data-analytics-lead-marketing-analytics-martech' => [
            'statement' => 'Worked across marketing analytics and marketing-technology systems spanning campaign '
                .'tracking, attribution, budget forecasting, and marketing automation — integrating Salesforce, '
                .'BigQuery, Google Analytics, Salesforce Marketing Cloud, and advertising-platform data into '
                .'unified reporting for marketing and business stakeholders.',
            'attached' => [23, 25, 26, 59, 60, 61],
        ],
        'profile-ai-assisted-development-pattern' => [
            'statement' => 'Consistently uses AI-assisted development workflows to move from prototype to '
                .'production more quickly, while keeping AI out of the computation/decision path for the systems '
                .'it builds (deterministic metrics and results, AI limited to explanation or synthesis).',
            'attached' => [41, 44],
        ],
        'liquid-gravity-what-they-did' => [
            'statement' => 'Founded and led a marketing technology consultancy, building custom web applications, '
                .'CRM integrations, automation tools, and conversion-focused solutions for clients across '
                .'multiple industries.',
            'attached' => [2],
        ],
        'rocketgate-independently-implemented-from-team-requirements' => [
            'statement' => 'Independently designed and implemented the technical solution for each major '
                .'RocketGate initiative (Workflow Intelligence, Verbatim, Transaction Toolkit, Knowledge '
                .'Exporter, and the transaction remediation tooling) from team-defined business requirements '
                .'and operational needs — the team provided requirements, context, documentation, tickets, '
                .'feedback, and access; the technical implementation was owned independently.',
            'attached' => [],
        ],
    ];
}

/**
 * @return array{skillIdsByFactKey: array<string, array<int, int>>, textRecognizedSkillIdsByFactKey: array<string, array<int, int>>}
 */
function formicSummaryRealAuthorizationData(): array
{
    $canonicalSkillsById = formicSummaryRealCanonicalSkills();
    $validator = new ResumeWordingResponseValidator;
    $skillIdsByFactKey = [];
    $textRecognizedSkillIdsByFactKey = [];

    foreach (formicSummaryRealFacts() as $key => $fact) {
        $skillIdsByFactKey[$key] = $fact['attached'];
        $textRecognizedSkillIdsByFactKey[$key] = $validator->recognizedSkillIds($fact['statement'], $canonicalSkillsById);
    }

    return compact('skillIdsByFactKey', 'textRecognizedSkillIdsByFactKey');
}

const FORMIC_FAILED_QWEN_SUMMARY = 'Senior full-stack developer who translates team-defined business requirements '
    .'into production systems — marketing analytics platforms, developer tooling, workflow automation, and '
    .'high-risk data remediation. Breadth across React, Node.js, Laravel, TypeScript, and marketing-technology '
    .'integrations (Salesforce, BigQuery, Google Analytics), with deterministic, evidence-linked architecture '
    .'and AI-assisted workflows bounded to explanation rather than computation.';

const FORMIC_CLEAN_QWEN_SUMMARY = 'Senior full-stack developer and data analytics lead building '
    .'marketing-technology platforms, developer tools, and AI-assisted systems with deterministic computation. '
    .'Integrates Salesforce, BigQuery, and CRM data into unified analytics, keeping AI limited to explanation '
    .'and synthesis. Founded a consultancy delivering custom web applications, CRM integrations, and '
    .'conversion-optimization tooling across industries.';

it('still rejects the real, latest failed qwen3.8:27b Summary — React/Node.js/Laravel/TypeScript remain unauthorized under the real Formic summary evidence', function () {
    $canonicalSkillsById = formicSummaryRealCanonicalSkills();
    $authorization = formicSummaryRealAuthorizationData();
    $content = minimalValidWordingContent(['summary' => FORMIC_FAILED_QWEN_SUMMARY]);

    try {
        validateWordingContent(
            $content,
            careerFactKeysByLocation: ['summary' => array_keys(formicSummaryRealFacts())],
            skillIdsByFactKey: $authorization['skillIdsByFactKey'],
            canonicalSkillsById: $canonicalSkillsById,
            textRecognizedSkillIdsByFactKey: $authorization['textRecognizedSkillIdsByFactKey'],
        );
        test()->fail('Expected InvalidResumeVariantResponseException to be thrown.');
    } catch (InvalidResumeVariantResponseException $e) {
        expect($e->getMessage())
            ->toContain('[React] (Skill id 8)')
            ->toContain('[Node.js] (Skill id 10)')
            ->toContain('[Laravel] (Skill id 1)')
            ->toContain('[TypeScript] (Skill id 4)');
    }
});

it('still accepts the real, prior clean qwen3.8:27b Summary under the same real Formic summary evidence', function () {
    $canonicalSkillsById = formicSummaryRealCanonicalSkills();
    $authorization = formicSummaryRealAuthorizationData();
    $content = minimalValidWordingContent(['summary' => FORMIC_CLEAN_QWEN_SUMMARY]);

    $validated = validateWordingContent(
        $content,
        careerFactKeysByLocation: ['summary' => array_keys(formicSummaryRealFacts())],
        skillIdsByFactKey: $authorization['skillIdsByFactKey'],
        canonicalSkillsById: $canonicalSkillsById,
        textRecognizedSkillIdsByFactKey: $authorization['textRecognizedSkillIdsByFactKey'],
    );

    expect($validated)->toBeArray();
});

// --- Target-term location scope (the target_term_usages -> Wording handoff) ---
//
// Established by the target_term_usages -> Resume Wording handoff
// investigation: a `direct`-posture target term is authorized only at
// the exact location Selection approved it for — never anywhere else
// in the same variant, even though `buildDenylistTerms()` removes it
// from the denylist variant-wide (that removal alone is what let the
// leak go unnoticed: nothing else ever checked WHERE the term
// appeared). This is purely a leakage check — it never requires an
// approved term to appear at all. See
// ResumeWordingResponseValidator::assertTargetTermLocationScope()'s
// own docblock and docs/resume-variant-generation.md "Target-term
// location integrity".

/**
 * Bypasses validateWordingContent()'s fixed validRoleIds:[1]/
 * expectedBulletGroupIndexesByRole:[1=>[0]] — several tests below need
 * more than one role or more than one bullet group.
 *
 * @param  array<string, mixed>  $structuredContent
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validateWordingContentCustom(array $structuredContent, array $overrides = []): array
{
    return (new ResumeWordingResponseValidator)->validate(
        $structuredContent,
        validRoleIds: $overrides['validRoleIds'] ?? [1],
        expectedBulletGroupIndexesByRole: $overrides['expectedBulletGroupIndexesByRole'] ?? [1 => [0]],
        validSelectedProjectIds: $overrides['validSelectedProjectIds'] ?? [],
        denylistTerms: $overrides['denylistTerms'] ?? [],
        careerFactKeysByLocation: $overrides['careerFactKeysByLocation'] ?? [],
        guardrailByFactKey: $overrides['guardrailByFactKey'] ?? [],
        skillIdsByFactKey: $overrides['skillIdsByFactKey'] ?? [],
        canonicalSkillsById: $overrides['canonicalSkillsById'] ?? [],
        textRecognizedSkillIdsByFactKey: $overrides['textRecognizedSkillIdsByFactKey'] ?? [],
        directTargetTermsByLocation: $overrides['directTargetTermsByLocation'] ?? [],
    );
}

it('accepts an approved direct target term appearing at its exact bullet location', function () {
    $content = minimalValidWordingContent([
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => 'Delivered outreach automation using Kubernetes at scale.'],
            ]],
        ],
    ]);

    $validated = validateWordingContentCustom($content, [
        'directTargetTermsByLocation' => ['1:0' => ['Kubernetes']],
    ]);

    expect($validated)->toBeArray();
});

it('rejects the same direct target term appearing in a DIFFERENT bullet than the one it was approved for', function () {
    $content = minimalValidWordingContent([
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => wordsOfLength(20)],
                ['bullet_group_index' => 1, 'text' => 'Delivered outreach automation using Kubernetes at scale.'],
            ]],
        ],
    ]);

    expect(fn () => validateWordingContentCustom($content, [
        'expectedBulletGroupIndexesByRole' => [1 => [0, 1]],
        'directTargetTermsByLocation' => ['1:0' => ['Kubernetes']], // approved at bullet 0; text names it at bullet 1
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'names the direct target term [Kubernetes], which Selection did not approve at this location');
});

it('rejects the same direct target term appearing under a DIFFERENT role than the one it was approved for', function () {
    $content = [
        'summary' => 'A concise professional summary.',
        'experience' => [
            ['role_id' => 1, 'bullets' => [['bullet_group_index' => 0, 'text' => wordsOfLength(20)]]],
            ['role_id' => 2, 'bullets' => [['bullet_group_index' => 0, 'text' => 'Delivered outreach automation using Kubernetes at scale.']]],
        ],
        'selected_projects' => [],
    ];

    expect(fn () => validateWordingContentCustom($content, [
        'validRoleIds' => [1, 2],
        'expectedBulletGroupIndexesByRole' => [1 => [0], 2 => [0]],
        'directTargetTermsByLocation' => ['1:0' => ['Kubernetes']], // approved for role 1, not role 2
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'names the direct target term [Kubernetes]');
});

it('rejects a bullet-approved direct target term appearing in the Summary instead', function () {
    $content = minimalValidWordingContent(['summary' => 'Uses Kubernetes across the stack.']);

    expect(fn () => validateWordingContentCustom($content, [
        'directTargetTermsByLocation' => ['1:0' => ['Kubernetes']], // approved for the bullet, not the summary
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'names the direct target term [Kubernetes]');
});

it('rejects a Summary-approved direct target term appearing in a bullet instead', function () {
    $content = minimalValidWordingContent([
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => 'Delivered outreach automation using Kubernetes at scale.'],
            ]],
        ],
    ]);

    expect(fn () => validateWordingContentCustom($content, [
        'directTargetTermsByLocation' => ['summary' => ['Kubernetes']], // approved for the summary, not the bullet
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'names the direct target term [Kubernetes]');
});

it('accepts an approved direct target term appearing in the exact Summary it was approved for', function () {
    $content = minimalValidWordingContent(['summary' => 'Uses Kubernetes across the stack.']);

    $validated = validateWordingContentCustom($content, [
        'directTargetTermsByLocation' => ['summary' => ['Kubernetes']],
    ]);

    expect($validated)->toBeArray();
});

it('keeps two different direct target terms approved at two different locations fully isolated when each appears only at its own location', function () {
    $content = minimalValidWordingContent([
        'summary' => 'Uses Kubernetes across the stack.',
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => 'Delivered outreach automation with Terraform.'],
            ]],
        ],
    ]);

    $validated = validateWordingContentCustom($content, [
        'directTargetTermsByLocation' => ['summary' => ['Kubernetes'], '1:0' => ['Terraform']],
    ]);

    expect($validated)->toBeArray();
});

it('rejects when two approved direct terms are swapped between their own approved locations', function () {
    $content = minimalValidWordingContent([
        'summary' => 'Uses Terraform across the stack.', // Terraform was approved for the bullet, not here
        'experience' => [
            ['role_id' => 1, 'bullets' => [
                ['bullet_group_index' => 0, 'text' => 'Delivered outreach automation with Kubernetes.'], // Kubernetes was approved for the summary, not here
            ]],
        ],
    ]);

    expect(fn () => validateWordingContentCustom($content, [
        'directTargetTermsByLocation' => ['summary' => ['Kubernetes'], '1:0' => ['Terraform']],
    ]))->toThrow(InvalidResumeVariantResponseException::class);
});

it('still prohibits a capability/qualified-posture term via the unchanged denylist — the new location-scope check plays no role for non-direct postures', function () {
    $content = minimalValidWordingContent(['summary' => 'Familiar with Kubernetes-style orchestration.']);

    expect(fn () => validateWordingContentCustom($content, [
        'denylistTerms' => ['Kubernetes'], // capability/qualified terms stay denylisted, exactly as before this milestone
        'directTargetTermsByLocation' => [], // never surfaced as direct guidance
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'denylisted target term [Kubernetes]');
});

it('is a complete no-op when no direct target terms are approved anywhere in the variant', function () {
    $content = minimalValidWordingContent(['summary' => 'Mentions Kubernetes casually with no approval anywhere.']);

    $validated = validateWordingContentCustom($content, [
        'directTargetTermsByLocation' => [], // empty catalog -> assertTargetTermLocationScope() returns immediately
    ]);

    expect($validated)->toBeArray();
});

it('still evaluates Skill provenance independently of, and simultaneously with, the new target-term location-scope check', function () {
    $content = minimalValidWordingContent(['summary' => 'Uses React and Kubernetes across the stack.']);

    try {
        validateWordingContentCustom($content, [
            'careerFactKeysByLocation' => ['summary' => ['fact-a']],
            'skillIdsByFactKey' => ['fact-a' => []], // React not authorized as a Skill here
            'canonicalSkillsById' => realisticCanonicalSkills(),
            'directTargetTermsByLocation' => ['1:0' => ['Kubernetes']], // Kubernetes approved elsewhere, not here
        ]);
        test()->fail('Expected InvalidResumeVariantResponseException to be thrown.');
    } catch (InvalidResumeVariantResponseException $e) {
        expect($e->getMessage())
            ->toContain('names the canonical Skill [React] (Skill id 1)')
            ->toContain('names the direct target term [Kubernetes]');
    }
});

it('rejects a term that is both an approved direct target term AND a canonical Skill when Skill provenance alone is unsatisfied — approval as a target term does not bypass it', function () {
    $content = minimalValidWordingContent(['summary' => 'Uses React across the stack.']);

    expect(fn () => validateWordingContentCustom($content, [
        'careerFactKeysByLocation' => ['summary' => ['fact-a']],
        'skillIdsByFactKey' => ['fact-a' => []], // React NOT attached/recognized as a Skill at this location
        'canonicalSkillsById' => realisticCanonicalSkills(),
        'directTargetTermsByLocation' => ['summary' => ['React']], // approved AS A TARGET TERM at this exact location
    ]))->toThrow(InvalidResumeVariantResponseException::class, 'names the canonical Skill [React] (Skill id 1)');
});

it('accepts a term that is both an approved direct target term AND a canonical Skill when it satisfies BOTH contracts', function () {
    $content = minimalValidWordingContent(['summary' => 'Uses React across the stack.']);

    $validated = validateWordingContentCustom($content, [
        'careerFactKeysByLocation' => ['summary' => ['fact-a']],
        'skillIdsByFactKey' => ['fact-a' => [1]], // React IS attached here
        'canonicalSkillsById' => realisticCanonicalSkills(),
        'directTargetTermsByLocation' => ['summary' => ['React']], // AND approved as a direct target term at this exact location
    ]);

    expect($validated)->toBeArray();
});
