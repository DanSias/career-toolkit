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
 *
 * Everything else — does a `Qualified` claim overreach in tone, is a
 * merchant-integration bullet phrased as authorship, does "AI" near
 * Knowledge Exporter mischaracterize it — is deliberately left to
 * live/human review (see docs/resume-variant-generation.md "Live
 * evaluation"), not approximated here with pattern-guessing that would
 * risk rejecting a correct sentence.
 */
final class ResumeWordingResponseValidator
{
    private const UNIVERSAL_BANNED_PHRASES = ['100% accuracy', '100% success rate', 'zero defects'];

    private const INDEPENDENT_IMPLEMENTATION_FACT_KEYS = [
        'rocketgate-independently-implemented-from-team-requirements',
        'rocketgate-workflow-intelligence-independently-implemented',
    ];

    private const PERSONAL_GITHUB_FACT_KEY = 'profile-github-personal-projects';

    /**
     * @param  array<string, mixed>  $structuredContent  Untrusted, decoded provider output.
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, array<int, int>>  $expectedBulletGroupIndexesByRole  role_id => bullet-group indexes Selection approved for it.
     * @param  array<int, string>  $denylistTerms
     * @param  array<string, array<int, string>>  $careerFactKeysByLocation  "role_id:index" or "summary" => career_fact_key[] backing that location.
     * @param  array<string, string|null>  $guardrailByFactKey
     * @return array<string, mixed>
     *
     * @throws InvalidResumeVariantResponseException
     */
    public function validate(
        array $structuredContent,
        array $validRoleIds,
        array $expectedBulletGroupIndexesByRole,
        array $denylistTerms,
        array $careerFactKeysByLocation,
        array $guardrailByFactKey,
    ): array {
        $validator = ValidatorFacade::make($structuredContent, $this->rules());

        $validator->after(function (Validator $validator) use (
            $structuredContent, $validRoleIds, $expectedBulletGroupIndexesByRole,
            $denylistTerms, $careerFactKeysByLocation, $guardrailByFactKey,
        ) {
            $experience = is_array($structuredContent['experience'] ?? null) ? $structuredContent['experience'] : [];
            $summary = is_string($structuredContent['summary'] ?? null) ? $structuredContent['summary'] : '';

            $this->assertCompleteness($validator, $experience, $validRoleIds, $expectedBulletGroupIndexesByRole);
            $this->assertDenylistRespected($validator, 'summary', $summary, $denylistTerms);
            $this->assertGuardrails($validator, 'summary', $summary, $careerFactKeysByLocation['summary'] ?? [], $guardrailByFactKey);

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
                }
            }
        });

        if ($validator->fails()) {
            throw new InvalidResumeVariantResponseException(
                'Resume Wording provider response failed validation: '
                .implode(' ', $validator->errors()->all())
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
}
