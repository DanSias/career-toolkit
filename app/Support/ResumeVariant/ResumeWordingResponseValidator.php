<?php

namespace App\Support\ResumeVariant;

use App\Exceptions\InvalidResumeVariantResponseException;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

/**
 * The single authoritative, deterministic check on the Resume Wording
 * provider's decoded response — structure, completeness against the
 * approved selection, and the specific, named guardrails this codebase
 * can check precisely without brittle general truth-scanning NLP:
 *
 * - the target-term deny list (a term may never appear in free text —
 *   see docs/domain-model.md "ResumeVariant" qualified-clause mechanism)
 * - Metric-guardrail single-quoted forbidden phrases (catches, e.g.,
 *   the Marketing Forecasting "20+ hours/month" guardrail precisely,
 *   because that guardrail text itself quotes the forbidden phrase —
 *   a guardrail that doesn't use quotes simply isn't caught here, a
 *   known, accepted limitation, not a general truthfulness guarantee)
 * - a small, fixed set of absolute, non-euphemistic banned phrases
 *   ("100% accuracy" etc.) that are never legitimate in this dataset
 *   regardless of context
 * - "solo"/"single-handedly" wherever the RocketGate
 *   independently-implemented facts are cited
 * - "GitHub" wherever a RocketGate-attributed fact is cited without
 *   also citing the distinct personal-GitHub fact
 * - location-scoped canonical-Skill provenance (see
 *   assertSkillProvenance()'s own docblock for the precise, narrow
 *   guarantee this one makes and does not make)
 * - location-scoped direct target-term usage (see
 *   assertTargetTermLocationScope()'s own docblock) — a `direct`
 *   target term may appear only at the exact location Selection
 *   approved it for, never elsewhere in the same variant
 *
 * Everything else — does a `Qualified` claim overreach in tone, is a
 * merchant-integration bullet phrased as authorship, does "AI" near
 * Knowledge Exporter mischaracterize it — is deliberately left to
 * live/human review (see docs/resume-variant-generation.md "Live
 * evaluation"), not approximated here with pattern-guessing that would
 * risk rejecting a correct sentence.
 *
 * One additional structural check lives here: a per-bullet word-count
 * ceiling (see BULLET_WORD_COUNT_CEILING) on Experience bullets and
 * Selected Project bullets. The prompt's ~20-28 word target and
 * ~32-word soft maximum are quality guidance only, deliberately NOT
 * enforced here — only a generous hard ceiling well above that is, so
 * this catches clearly excessive output (the kind of 46-word run-on
 * bullet that motivated this check) without making normal technical
 * prose brittle over a single word of genuine necessity.
 */
final class ResumeWordingResponseValidator
{
    private const UNIVERSAL_BANNED_PHRASES = ['100% accuracy', '100% success rate', 'zero defects'];

    /**
     * A hard ceiling, not the ~20-28 word target/~32-word soft maximum
     * the prompt asks for — comfortably above the ~36-word "unusually
     * important technical distinction" allowance so this never rejects
     * genuinely necessary prose, while still catching clearly
     * excessive output.
     */
    private const BULLET_WORD_COUNT_CEILING = 40;

    private const INDEPENDENT_IMPLEMENTATION_FACT_KEYS = [
        'rocketgate-independently-implemented-from-team-requirements',
        'rocketgate-workflow-intelligence-independently-implemented',
    ];

    private const PERSONAL_GITHUB_FACT_KEY = 'profile-github-personal-projects';

    /**
     * @param  array<string, mixed>  $structuredContent  Untrusted, decoded provider output.
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, array<int, int>>  $expectedBulletGroupIndexesByRole  role_id => bullet-group indexes Selection approved for it.
     * @param  array<int, int>  $validSelectedProjectIds  The independent Projects Selection actually approved.
     * @param  array<int, string>  $denylistTerms
     * @param  array<string, array<int, string>>  $careerFactKeysByLocation  "role_id:index", "project:project_id", or "summary" => career_fact_key[] backing that location.
     * @param  array<string, string|null>  $guardrailByFactKey
     * @param  array<string, array<int, int>>  $skillIdsByFactKey  career_fact_key => Skill id[] attached to that fact.
     * @param  array<int, string>  $canonicalSkillsById  Every canonical Skill (id => name) that could legitimately appear anywhere in this profile's eligible corpus — the recognition catalog for assertSkillProvenance(), not a per-location authorization set.
     * @param  array<string, array<int, int>>  $textRecognizedSkillIdsByFactKey  career_fact_key => Skill id[] recognized (via recognizedSkillIds()) in that fact's own evidence-bearing text (statement + metric scope_note/guardrail) — computed once per fact by the caller, never re-scanned per location. See authorizedSkillIds()'s own docblock.
     * @param  array<string, array<int, string>>  $directTargetTermsByLocation  "summary" or "role_id:index" => direct-posture target term[] Selection approved at that exact location. See assertTargetTermLocationScope()'s own docblock.
     * @return array<string, mixed>
     *
     * @throws InvalidResumeVariantResponseException
     */
    public function validate(
        array $structuredContent,
        array $validRoleIds,
        array $expectedBulletGroupIndexesByRole,
        array $validSelectedProjectIds,
        array $denylistTerms,
        array $careerFactKeysByLocation,
        array $guardrailByFactKey,
        array $skillIdsByFactKey,
        array $canonicalSkillsById,
        array $textRecognizedSkillIdsByFactKey,
        array $directTargetTermsByLocation,
    ): array {
        $validator = ValidatorFacade::make($structuredContent, $this->rules());

        $allDirectTargetTerms = array_values(array_unique(array_merge([], ...array_values($directTargetTermsByLocation))));

        $validator->after(function (Validator $validator) use (
            $structuredContent, $validRoleIds, $expectedBulletGroupIndexesByRole, $validSelectedProjectIds,
            $denylistTerms, $careerFactKeysByLocation, $guardrailByFactKey, $skillIdsByFactKey, $canonicalSkillsById,
            $textRecognizedSkillIdsByFactKey, $directTargetTermsByLocation, $allDirectTargetTerms,
        ) {
            $experience = is_array($structuredContent['experience'] ?? null) ? $structuredContent['experience'] : [];
            $selectedProjects = is_array($structuredContent['selected_projects'] ?? null) ? $structuredContent['selected_projects'] : [];
            $summary = is_string($structuredContent['summary'] ?? null) ? $structuredContent['summary'] : '';

            $this->assertCompleteness($validator, $experience, $validRoleIds, $expectedBulletGroupIndexesByRole);
            $this->assertSelectedProjectsCompleteness($validator, $selectedProjects, $validSelectedProjectIds);
            $this->assertDenylistRespected($validator, 'summary', $summary, $denylistTerms);
            $this->assertGuardrails($validator, 'summary', $summary, $careerFactKeysByLocation['summary'] ?? [], $guardrailByFactKey);
            $this->assertSkillProvenance(
                $validator, 'summary', $summary,
                $this->authorizedSkillIds($careerFactKeysByLocation['summary'] ?? [], $skillIdsByFactKey, $textRecognizedSkillIdsByFactKey),
                $canonicalSkillsById,
            );
            $this->assertTargetTermLocationScope(
                $validator, 'summary', $summary,
                $directTargetTermsByLocation['summary'] ?? [],
                $allDirectTargetTerms,
            );

            foreach ($experience as $roleIndex => $role) {
                if (! is_array($role)) {
                    continue;
                }

                $roleId = $role['role_id'] ?? null;
                $bullets = is_array($role['bullets'] ?? null) ? $role['bullets'] : [];

                foreach ($bullets as $bulletIndex => $bullet) {
                    if (! is_array($bullet)) {
                        continue;
                    }

                    $text = is_string($bullet['text'] ?? null) ? $bullet['text'] : '';
                    $groupIndex = $bullet['bullet_group_index'] ?? null;
                    $locationKey = "{$roleId}:{$groupIndex}";
                    $field = "experience.{$roleIndex}.bullets.{$bulletIndex}.text";

                    $this->assertDenylistRespected($validator, $field, $text, $denylistTerms);
                    $this->assertGuardrails($validator, $field, $text, $careerFactKeysByLocation[$locationKey] ?? [], $guardrailByFactKey);
                    $this->assertWordCountCeiling($validator, $field, $text);
                    $this->assertSkillProvenance(
                        $validator, $field, $text,
                        $this->authorizedSkillIds($careerFactKeysByLocation[$locationKey] ?? [], $skillIdsByFactKey, $textRecognizedSkillIdsByFactKey),
                        $canonicalSkillsById,
                    );
                    $this->assertTargetTermLocationScope(
                        $validator, $field, $text,
                        $directTargetTermsByLocation[$locationKey] ?? [],
                        $allDirectTargetTerms,
                    );
                }
            }

            // Selected Projects deliberately never receive a
            // assertTargetTermLocationScope() call: ResumeTermUsageLocation
            // has no "project" case, so a target-term usage can never be
            // located at one — there is nothing to authorize or check.
            foreach ($selectedProjects as $projectIndex => $project) {
                if (! is_array($project)) {
                    continue;
                }

                $projectId = $project['project_id'] ?? null;
                $text = is_string($project['text'] ?? null) ? $project['text'] : '';
                $locationKey = "project:{$projectId}";
                $field = "selected_projects.{$projectIndex}.text";

                $this->assertDenylistRespected($validator, $field, $text, $denylistTerms);
                $this->assertGuardrails($validator, $field, $text, $careerFactKeysByLocation[$locationKey] ?? [], $guardrailByFactKey);
                $this->assertWordCountCeiling($validator, $field, $text);
                $this->assertSkillProvenance(
                    $validator, $field, $text,
                    $this->authorizedSkillIds($careerFactKeysByLocation[$locationKey] ?? [], $skillIdsByFactKey, $textRecognizedSkillIdsByFactKey),
                    $canonicalSkillsById,
                );
            }
        });

        if ($validator->fails()) {
            throw new InvalidResumeVariantResponseException(
                'Resume Wording provider response failed validation: '
                .implode(' ', $validator->errors()->all()),
                context: $structuredContent,
            );
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'summary' => ['required', 'string'],
            'experience' => ['present', 'array'],
            'experience.*.role_id' => ['required', 'integer'],
            'experience.*.bullets' => ['present', 'array'],
            'experience.*.bullets.*.bullet_group_index' => ['required', 'integer'],
            'experience.*.bullets.*.text' => ['required', 'string'],
            'selected_projects' => ['present', 'array'],
            'selected_projects.*.project_id' => ['required', 'integer'],
            'selected_projects.*.text' => ['required', 'string'],
        ];
    }

    /**
     * Every (role_id, bullet_group_index) pair Selection approved must
     * appear in the Wording response exactly once — no missing bullets,
     * no duplicates, no invented ones. Mirrors
     * JobMatchResponseValidator::assertCompleteness().
     *
     * @param  array<int, mixed>  $experience
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, array<int, int>>  $expectedBulletGroupIndexesByRole
     */
    private function assertCompleteness(
        Validator $validator,
        array $experience,
        array $validRoleIds,
        array $expectedBulletGroupIndexesByRole,
    ): void {
        $expected = [];
        foreach ($expectedBulletGroupIndexesByRole as $roleId => $indexes) {
            foreach ($indexes as $index) {
                $expected[] = "{$roleId}:{$index}";
            }
        }

        $seen = [];

        foreach ($experience as $role) {
            if (! is_array($role)) {
                continue;
            }

            $roleId = $role['role_id'] ?? null;

            if (is_int($roleId) && ! in_array($roleId, $validRoleIds, true)) {
                $validator->errors()->add('experience', "role_id [{$roleId}] was not part of the approved selection.");

                continue;
            }

            foreach ((is_array($role['bullets'] ?? null) ? $role['bullets'] : []) as $bullet) {
                $index = is_array($bullet) ? ($bullet['bullet_group_index'] ?? null) : null;
                $key = "{$roleId}:{$index}";

                if (in_array($key, $seen, true)) {
                    $validator->errors()->add('experience', "bullet (role_id [{$roleId}], index [{$index}]) appears more than once.");

                    continue;
                }

                $seen[] = $key;
            }
        }

        $missing = array_diff($expected, $seen);
        if ($missing !== []) {
            $validator->errors()->add('experience', 'Response is missing wording for approved bullet(s): '.implode(', ', $missing).'.');
        }

        $unexpected = array_diff($seen, $expected);
        if ($unexpected !== []) {
            $validator->errors()->add('experience', 'Response includes wording for bullet(s) never approved by Selection: '.implode(', ', $unexpected).'.');
        }
    }

    /**
     * Every project_id Selection approved must appear in
     * `selected_projects` exactly once — no missing, no duplicates, no
     * invented ones (including an outright professional project id,
     * which is impossible to legitimately approve in the first place).
     * Mirrors assertCompleteness() exactly, scoped to project ids
     * instead of (role_id, bullet_group_index) pairs since v1 carries
     * exactly one bullet per Selected Project.
     *
     * @param  array<int, mixed>  $selectedProjects
     * @param  array<int, int>  $validSelectedProjectIds
     */
    private function assertSelectedProjectsCompleteness(Validator $validator, array $selectedProjects, array $validSelectedProjectIds): void
    {
        $seen = [];

        foreach ($selectedProjects as $project) {
            $id = is_array($project) ? ($project['project_id'] ?? null) : null;

            if (! is_int($id)) {
                continue;
            }

            if (in_array($id, $seen, true)) {
                $validator->errors()->add('selected_projects', "project_id [{$id}] appears more than once.");

                continue;
            }

            $seen[] = $id;
        }

        $missing = array_diff($validSelectedProjectIds, $seen);
        if ($missing !== []) {
            $validator->errors()->add('selected_projects', 'Response is missing wording for approved Selected Project(s): '.implode(', ', $missing).'.');
        }

        $unexpected = array_diff($seen, $validSelectedProjectIds);
        if ($unexpected !== []) {
            $validator->errors()->add('selected_projects', 'Response includes wording for Selected Project(s) never approved by Selection: '.implode(', ', $unexpected).'.');
        }
    }

    /**
     * A generous hard ceiling above the prompt's own ~20-28 word
     * target/~32-word soft maximum — see BULLET_WORD_COUNT_CEILING's
     * own docblock for why this stays intentionally permissive.
     */
    private function assertWordCountCeiling(Validator $validator, string $field, string $text): void
    {
        $wordCount = $this->wordCount($text);

        if ($wordCount > self::BULLET_WORD_COUNT_CEILING) {
            $validator->errors()->add(
                $field,
                "Generated bullet is {$wordCount} words, over the ".self::BULLET_WORD_COUNT_CEILING
                .'-word structural ceiling — Wording must compress to the strongest defensible statement rather than enumerating every supported detail.',
            );
        }
    }

    private function wordCount(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text));

        return $words === false ? 0 : count(array_filter($words, fn (string $word) => $word !== ''));
    }

    /**
     * A target term may only enter final output through the
     * deterministic qualified-clause mechanism — never through the
     * model's own free text, under any framing, even for a term it was
     * approved to discuss.
     *
     * @param  array<int, string>  $denylistTerms
     */
    private function assertDenylistRespected(Validator $validator, string $field, string $text, array $denylistTerms): void
    {
        foreach ($denylistTerms as $term) {
            if ($term !== '' && mb_stripos($text, $term) !== false) {
                $validator->errors()->add($field, "Generated text contains the denylisted target term [{$term}] — it may only appear through the controlled qualified-clause mechanism.");
            }
        }
    }

    /**
     * @param  array<int, string>  $careerFactKeys
     * @param  array<string, string|null>  $guardrailByFactKey
     */
    private function assertGuardrails(Validator $validator, string $field, string $text, array $careerFactKeys, array $guardrailByFactKey): void
    {
        foreach (self::UNIVERSAL_BANNED_PHRASES as $phrase) {
            if (mb_stripos($text, $phrase) !== false) {
                $validator->errors()->add($field, "Generated text contains the unsupported phrase \"{$phrase}\".");
            }
        }

        $citesIndependentImplementation = array_intersect(self::INDEPENDENT_IMPLEMENTATION_FACT_KEYS, $careerFactKeys) !== [];

        if ($citesIndependentImplementation) {
            foreach (['solo', 'single-handedly'] as $phrase) {
                if (mb_stripos($text, $phrase) !== false) {
                    $validator->errors()->add($field, "Generated text describes independently-implemented RocketGate work as \"{$phrase}\" — the canonical fact deliberately does not claim isolation from the team.");
                }
            }
        }

        $citesRocketGateFact = collect($careerFactKeys)->contains(fn (string $key) => str_starts_with($key, 'rocketgate-'));
        $citesPersonalGitHubFact = in_array(self::PERSONAL_GITHUB_FACT_KEY, $careerFactKeys, true);

        if ($citesRocketGateFact && ! $citesPersonalGitHubFact && mb_stripos($text, 'GitHub') !== false) {
            $validator->errors()->add($field, 'Generated text mentions GitHub alongside RocketGate-attributed evidence — RocketGate\'s source control is GitLab; GitHub is only evidenced for personal projects.');
        }

        foreach ($careerFactKeys as $key) {
            $guardrail = $guardrailByFactKey[$key] ?? null;

            if ($guardrail === null) {
                continue;
            }

            if (preg_match_all("/'([^']+)'/", $guardrail, $matches) > 0) {
                foreach ($matches[1] as $forbidden) {
                    if (mb_stripos($text, $forbidden) !== false) {
                        $validator->errors()->add($field, "Generated text contains \"{$forbidden}\", which CareerFact [{$key}]'s Metric guardrail explicitly forbids.");
                    }
                }
            }
        }
    }

    /**
     * The authorization set assertSkillProvenance() checks a recognized
     * canonical-Skill mention against, for one prose location: the union
     * of Skill ids attached to every CareerFact supplied to that
     * location, PLUS Skill ids recognized (via recognizedSkillIds()) in
     * those same facts' own evidence-bearing text. A location that cites
     * no facts authorizes nothing.
     *
     * This is the additive fact-local evidence contract established by
     * the Skill-provenance authority-model investigation, matching
     * JobMatchPromptV3's own evidence boundary ("that fact's own
     * statement, attached Skills, metric/guardrail/scope_note"): a
     * Skill relation is positive evidence, and a canonical Skill name
     * genuinely present in a fact's own statement/metric text is
     * ALSO positive evidence — neither is a restriction on the other,
     * and BOTH remain strictly fact-local. A Skill established only by
     * a *different* CareerFact (whether cited at another location or
     * simply not cited here) never enters this set — see
     * docs/resume-variant-generation.md "Skill provenance" for the full
     * investigation and why treating the Skill relation as an exhaustive
     * restriction on statement content contradicted the codebase's own
     * pre-existing evidence model.
     *
     * Public, and also called by
     * `GenerateResumeVariant::buildWordingInput()` to compute
     * `summary_authorized_skills` — the model-facing allow-list surfaced
     * to Wording so it can execute this exact authorization rule without
     * having to infer it from prose while seeing the whole request's
     * evidence at once. That is deliberately the ONLY caller of this
     * method outside this class: there is exactly one definition of
     * Skill authorization, and the model-facing list and this validator
     * both resolve to it — see docs/resume-variant-generation.md "Skill
     * provenance". Surfacing a name in that list is a generation-time
     * affordance only; it grants no authorization of its own; this
     * method (and assertSkillProvenance()) remain the sole, fail-closed
     * authority over what generated prose may actually name.
     *
     * @param  array<int, string>  $careerFactKeys
     * @param  array<string, array<int, int>>  $skillIdsByFactKey
     * @param  array<string, array<int, int>>  $textRecognizedSkillIdsByFactKey
     * @return array<int, int>
     */
    public function authorizedSkillIds(array $careerFactKeys, array $skillIdsByFactKey, array $textRecognizedSkillIdsByFactKey): array
    {
        $ids = [];

        foreach ($careerFactKeys as $key) {
            foreach ($skillIdsByFactKey[$key] ?? [] as $id) {
                $ids[$id] = true;
            }

            foreach ($textRecognizedSkillIdsByFactKey[$key] ?? [] as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * A narrow, location-scoped guarantee: *if generated prose explicitly
     * names a recognized canonical Skill, that Skill must be authorized
     * by the CareerFacts supplied to that exact prose location — either
     * by an attached Skill relation on one of those facts, or by that
     * same canonical name genuinely appearing in one of those facts' own
     * evidence-bearing text (see authorizedSkillIds()'s own docblock for
     * the full additive contract and why both sources count).*
     *
     * This is NOT a general technology-provenance guarantee. It exists
     * specifically to catch the cross-location leakage the first live
     * qwen3.8:27b Resume Wording evaluation demonstrated: the generated
     * summary named "React" and "Laravel", technologies genuinely true
     * of the candidate and genuinely present elsewhere in the very same
     * Wording request (attached to other roles'/projects' own supplied
     * CareerFacts), but not authorized by any of the four facts actually
     * supplied as summary evidence — see
     * docs/resume-variant-generation.md "Skill provenance" for the full
     * investigation this check is built from.
     *
     * Matching is deliberately conservative: a case-sensitive, whole-
     * word-bounded (`\b`) literal match of each canonical Skill's exact
     * `name` string against the generated text — no stemming, no
     * punctuation stripping, no alias table (none exists — see
     * App\Models\Skill), no decomposition into generic tokens. Matching
     * the full canonical name rather than a decomposed token is what
     * keeps this safe against substring collisions: `\bGit\b` cannot
     * match inside "GitHub" or "GitLab" because there is no `\b`
     * boundary between adjacent word characters, so this needs no
     * special-cased exclusion list for that class of near-miss.
     *
     * When two DIFFERENT canonical Skills' exact matches overlap in the
     * text (e.g. "Salesforce", id 23, matches inside "Salesforce
     * Marketing Cloud", id 24, because the shorter name is wholly
     * contained in the longer one) — see recognizedSkillIds() — only
     * the longer span is kept; the shorter, overlapping match is
     * suppressed as a sub-match of it. A separate, non-overlapping
     * occurrence of the shorter name elsewhere in the same text is
     * unaffected and still recognized independently (e.g. "Salesforce
     * and Salesforce Marketing Cloud" recognizes both). This is a
     * generic longest-span-wins overlap rule, not a Salesforce-specific
     * exclusion — see docs/resume-variant-generation.md "Skill
     * provenance" for the investigation and the confirmation that no
     * other overlapping pair exists in the current canonical catalog.
     *
     * Known, accepted, INTENTIONAL limitations:
     *
     * - This only recognizes the canonical `name` on record. It does
     *   not know "Node" refers to the canonical Skill "Node.js" — a
     *   bare "Node" mention is invisible to this check entirely,
     *   exactly as it was in the live run that motivated this.
     *   Extending recognition to informal short forms would require an
     *   alias/normalization table this codebase deliberately does not
     *   build (see the investigation this check came from) — this is a
     *   documented gap, not an oversight. This is NOT the same thing as
     *   the overlap rule above: overlap resolution only ever suppresses
     *   a match between two Skills that BOTH exactly matched via the
     *   existing literal rules — it never invents a match "Node" doesn't
     *   otherwise have against "Node.js".
     * - Matching is case-sensitive on purpose, so a differently-cased
     *   mention (e.g. "react" for "React") is likewise invisible.
     *
     * @param  array<int, int>  $authorizedSkillIds
     * @param  array<int, string>  $canonicalSkillsById
     */
    private function assertSkillProvenance(Validator $validator, string $field, string $text, array $authorizedSkillIds, array $canonicalSkillsById): void
    {
        foreach ($this->recognizedSkillIds($text, $canonicalSkillsById) as $skillId) {
            if (! in_array($skillId, $authorizedSkillIds, true)) {
                $validator->errors()->add(
                    $field,
                    "Generated text names the canonical Skill [{$canonicalSkillsById[$skillId]}] (Skill id {$skillId}), which is not authorized by the CareerFacts supplied to this location."
                );
            }
        }
    }

    /**
     * Every canonical Skill genuinely recognized in $text, applying
     * longest-span-wins overlap resolution across ALL canonical Skills'
     * matches at once (not per-pair, not name-specific): every exact,
     * word-bounded occurrence of every canonical name is first located
     * with its text position; occurrences are then considered longest
     * first, and an occurrence is kept only if it does not overlap any
     * occurrence already kept — which is exactly "the longer span wins,
     * a shorter span wholly contained within it is suppressed," and
     * also correctly handles two separate, non-overlapping occurrences
     * of the same or different names (both kept, since they never
     * compete for the same text).
     *
     * Ties (two overlapping occurrences of exactly equal span length)
     * are broken by lower Skill id, for full determinism regardless of
     * PHP's associative-array iteration order. No such tie exists in
     * the current canonical catalog and, by construction, none ever
     * can: two Skills can never share one profile's exact `name` string
     * (name and slug are effectively 1:1, and `slug` is uniquely
     * constrained per profile — see App\Models\Skill), and an
     * equal-length overlap between two DIFFERENT literal strings that
     * isn't an identical span requires one to end where the other
     * begins mid-word on both sides, which the shared canonical
     * catalog inspected for this change does not contain anywhere.
     * This tie-break exists defensively, not because it fires today.
     *
     * Public, not private: reused for two further purposes beyond
     * scanning generated prose against canonical Skills — (1)
     * GenerateResumeVariant calls this same method once per eligible
     * CareerFact, against that fact's own evidence-bearing text, to
     * compute the fact-local textual-Skill authorization map (see
     * authorizedSkillIds()'s own docblock), and (2)
     * assertTargetTermLocationScope() calls it again with a target-term
     * catalog instead of a Skill catalog — a self-keyed `term => term`
     * map works identically, since nothing here assumes the key is a
     * Skill id specifically; it is only ever used as an opaque
     * identifier returned to the caller. In both reuses this is the
     * same recognition algorithm, never a second one.
     *
     * @template TCatalogKey of int|string
     *
     * @param  array<TCatalogKey, string>  $canonicalSkillsById  Recognition catalog: opaque id/key => exact name to match. Usually a canonical Skill's `id => name`, but assertTargetTermLocationScope() passes a self-keyed `term => term` map instead.
     * @return array<int, TCatalogKey> Distinct catalog keys recognized, deduplicated — Skill ids when called with a Skill catalog, term strings when called with a term catalog.
     */
    public function recognizedSkillIds(string $text, array $canonicalSkillsById): array
    {
        $occurrences = [];

        foreach ($canonicalSkillsById as $skillId => $skillName) {
            if ($skillName === '') {
                continue;
            }

            $pattern = '/\b'.preg_quote($skillName, '/').'\b/u';

            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($matches[0] as [$matchedText, $start]) {
                $occurrences[] = [
                    'skill_id' => $skillId,
                    'start' => $start,
                    'end' => $start + strlen($matchedText),
                ];
            }
        }

        usort($occurrences, fn (array $a, array $b) => ($b['end'] - $b['start']) <=> ($a['end'] - $a['start'])
            ?: $a['skill_id'] <=> $b['skill_id']);

        $acceptedSpans = [];
        $recognizedSkillIds = [];

        foreach ($occurrences as $occurrence) {
            $overlapsAnAcceptedSpan = false;

            foreach ($acceptedSpans as $acceptedSpan) {
                if ($occurrence['start'] < $acceptedSpan['end'] && $occurrence['end'] > $acceptedSpan['start']) {
                    $overlapsAnAcceptedSpan = true;
                    break;
                }
            }

            if ($overlapsAnAcceptedSpan) {
                continue;
            }

            $acceptedSpans[] = $occurrence;
            $recognizedSkillIds[$occurrence['skill_id']] = true;
        }

        return array_keys($recognizedSkillIds);
    }

    /**
     * A narrow, location-scoped guarantee, structurally identical in
     * shape to assertSkillProvenance(): *if generated prose explicitly
     * names a direct-posture target term, that term must be one
     * Selection approved at this exact location.*
     *
     * Closes the leak `buildDenylistTerms()` alone could not:
     * `denylistTerms` correctly keeps a `capability`/`qualified` term
     * out of free text everywhere (unchanged by this check), but a
     * `direct`-posture term is simply absent from that list — variant-
     * wide, not scoped to the one location it was actually approved
     * for. Before this check, nothing prevented (or even noticed) a
     * direct term appearing at a location Selection never approved it
     * for. This check is purely negative (leakage prevention) — it
     * does NOT require an approved term to appear at all; whether
     * Wording actually uses one remains encouraged, never mandated
     * (see `## Direct target-term guidance` in
     * `ResumeWordingPromptV2::systemPrompt()`).
     *
     * Reuses recognizedSkillIds() unchanged for matching — a term
     * catalog is just a self-keyed map (`term => term`) passed where
     * that method expects `Skill id => Skill name`; its return value
     * (the "ids") are then simply the term strings themselves. This is
     * the same case-sensitive, whole-word-bounded, longest-span-wins
     * matching already used for Skills — no second recognition
     * algorithm, and the existing overlap resolution already protects
     * against one target term being a genuine substring of another,
     * exactly as it does for canonical Skills, with no term-specific
     * exceptions needed.
     *
     * A term that happens to also be a canonical Skill name is
     * unaffected: this check and assertSkillProvenance() run as two
     * fully independent passes over the same text, each against its
     * own catalog and its own authorization set. An occurrence must
     * satisfy both — this check never substitutes for, or bypasses,
     * Skill provenance.
     *
     * @param  array<int, string>  $authorizedTermsAtThisLocation
     * @param  array<int, string>  $allDirectTargetTerms  Every direct-posture term approved anywhere in this variant — the recognition catalog, not a per-location authorization set.
     */
    private function assertTargetTermLocationScope(Validator $validator, string $field, string $text, array $authorizedTermsAtThisLocation, array $allDirectTargetTerms): void
    {
        if ($allDirectTargetTerms === []) {
            return;
        }

        $termCatalog = array_combine($allDirectTargetTerms, $allDirectTargetTerms);

        foreach ($this->recognizedSkillIds($text, $termCatalog) as $term) {
            if (! in_array($term, $authorizedTermsAtThisLocation, true)) {
                $validator->errors()->add(
                    $field,
                    "Generated text names the direct target term [{$term}], which Selection did not approve at this location."
                );
            }
        }
    }
}
