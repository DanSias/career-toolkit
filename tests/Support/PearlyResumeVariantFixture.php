<?php

namespace Tests\Support;

use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Employer;
use App\Models\JobMatch;
use App\Models\ResumeVariant;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;

/**
 * Reconstructs, via ordinary Eloquent creates in the normal test
 * database, the exact real ResumeVariant #2 generated for the Pearly
 * job posting (Senior Software Engineer - Full Stack) in the isolated,
 * disposable live-eval database — see
 * app/Console/Commands/LiveEval/RunResumeVariantLiveEvaluation.php.
 * Production code must never reference that database or a specific
 * ResumeVariant id directly; this fixture is the decoupled equivalent,
 * following the same convention as the other tests/Support/*Fixtures
 * classes (a PHP builder, not a raw JSON blob) — just seeded with
 * real, already-human-reviewed content instead of synthetic
 * placeholder text.
 *
 * Deliberately omits what App\Support\ResumeDocument\GenerateResumeDocument
 * never reads: CareerFact rows, bullet citations, target-term usages,
 * and Project rows (v1 never renders a project label — see
 * docs/domain-model.md "ResumeVariant"). All 12 bullets are created
 * with a null project_id. JobMatch/JobAnalysis/JobPosting are bare
 * factory defaults — ResumeDocument assembly never reads through
 * job_match_id at all.
 */
final class PearlyResumeVariantFixture
{
    public static function generate(): ResumeVariant
    {
        $user = User::factory()->create(['email' => 'dan.sias@gmail.com']);
        $profile = CareerProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'Daniel Sias',
            'email' => 'dan.sias@gmail.com',
            'phone' => null,
            'location' => null,
            'portfolio_url' => null,
            'github_url' => null,
        ]);

        $rocketgate = Employer::factory()->create(['career_profile_id' => $profile->id, 'name' => 'RocketGate']);
        $rocketgateRole = Role::factory()->create([
            'employer_id' => $rocketgate->id,
            'title' => 'Developer Support Engineer',
            'start_year' => 2025, 'start_month' => 5, 'end_year' => null, 'end_month' => null,
        ]);

        $pearson = Employer::factory()->create(['career_profile_id' => $profile->id, 'name' => 'Pearson Online Learning Services']);
        $pearsonRole = Role::factory()->create([
            'employer_id' => $pearson->id,
            'title' => 'Data & Analytics Lead Developer / Data Analyst',
            'start_year' => 2015, 'start_month' => 9, 'end_year' => 2024, 'end_month' => 7,
        ]);

        $skills = collect(self::skills())->mapWithKeys(
            fn (array $s) => [$s['name'] => Skill::factory()->create([
                'career_profile_id' => $profile->id, 'name' => $s['name'], 'category' => $s['category'],
            ])]
        );

        $education = collect(self::education())->map(
            fn (array $e) => Education::factory()->create([
                'career_profile_id' => $profile->id,
                'institution' => $e['institution'], 'degree' => $e['degree'], 'field_of_study' => $e['field_of_study'],
                'start_year' => $e['start_year'], 'end_year' => $e['end_year'],
            ])
        );

        $jobMatch = JobMatch::factory()->create(['career_profile_id' => $profile->id]);

        $variant = ResumeVariant::factory()->create([
            'career_profile_id' => $profile->id,
            'job_match_id' => $jobMatch->id,
            'schema_version' => '1.1',
            'selection_prompt_version' => 'resume-selection-v1.2',
            'wording_prompt_version' => 'resume-wording-v1',
            'selection_generated_by' => 'openai:gpt-5.6-terra',
            'wording_generated_by' => 'openai:gpt-5.6-terra',
            'summary' => self::summary(),
        ]);

        foreach (self::roles($rocketgateRole, $pearsonRole) as $roleData) {
            $experienceRole = $variant->experienceRoles()->create([
                'role_id' => $roleData['role']->id,
                'employer_name' => $roleData['employer_name'],
                'display_title' => $roleData['display_title'],
                'start_year' => $roleData['role']->start_year,
                'start_month' => $roleData['role']->start_month,
                'end_year' => $roleData['role']->end_year,
                'end_month' => $roleData['role']->end_month,
                'display_order' => $roleData['display_order'],
            ]);

            foreach ($roleData['bullets'] as $order => $text) {
                $experienceRole->bullets()->create([
                    'resume_variant_id' => $variant->id,
                    'project_id' => null,
                    'display_order' => $order + 1,
                    'text' => $text,
                ]);
            }
        }

        foreach (self::skills() as $order => $s) {
            $variant->skillSelections()->create([
                'skill_id' => $skills[$s['name']]->id,
                'name' => $s['name'],
                'category' => $s['category'],
                'display_order' => $order + 1,
            ]);
        }

        foreach ($education as $order => $educationModel) {
            $variant->educationSelections()->create([
                'education_id' => $educationModel->id,
                'institution' => $educationModel->institution,
                'degree' => $educationModel->degree,
                'field_of_study' => $educationModel->field_of_study,
                'start_year' => $educationModel->start_year,
                'end_year' => $educationModel->end_year,
                'display_order' => $order + 1,
            ]);
        }

        return $variant->fresh(['careerProfile', 'experienceRoles.bullets', 'skillSelections', 'educationSelections']);
    }

    private static function summary(): string
    {
        return 'Full-stack developer who independently delivers technical solutions from business requirements, '
            .'including production transaction-remediation tooling and analytics platforms; built React/Node.js '
            .'Nexus to unify BigQuery and Salesforce data, reducing reporting time by 85%, and partnered with '
            .'marketing, finance, and engineering stakeholders on scalable applications for analytics, forecasting, '
            .'and operational reporting.';
    }

    /**
     * @return array<int, array{employer_name: string, display_title: string, role: Role, display_order: int, bullets: array<int, string>}>
     */
    private static function roles(Role $rocketgateRole, Role $pearsonRole): array
    {
        return [
            [
                'employer_name' => 'RocketGate',
                'display_title' => 'Developer Support Engineer',
                'role' => $rocketgateRole,
                'display_order' => 0,
                'bullets' => [
                    'Built production remediation tooling that deterministically resolved missing transaction '
                        .'metadata, used reviewable dry runs, throttled idempotent live API writes, supported '
                        .'resumable recovery, verified every correction against the source system, and maintained '
                        .'audit trails and completeness audits across 32,000+ corrected transaction records.',
                    'Owned the design and development of Workflow Intelligence, a Laravel/Vue platform that unifies '
                        .'Jira work items with GitLab merge-request and deployment activity into an evidence-linked '
                        .'workflow model for engineering dashboards, stage history, and delivery progress.',
                    'Built Knowledge Exporter, a provider-abstracted, read-only TypeScript/Next.js pipeline that '
                        .'converts Freshdesk and Confluence content into deterministic Markdown, uses SHA-256 '
                        .'comparisons to create, update, or skip files, and produces auditable per-run change reports.',
                    'Built Verbatim, a documentation Q&A and support-reply drafting system with deterministic '
                        .'retrieval and confidence scoring, LLM synthesis over pre-selected source chunks, and '
                        .'citations mapped to exact retrieved sections; also created a centralized searchable '
                        .'developer knowledge base with SDK examples and payment-gateway integration guidance.',
                    'Served as technical contact for new merchant integrations by assessing customer technology '
                        .'stacks, directing teams to appropriate PHP or Node.js integration approaches, testing '
                        .'payment flows, and troubleshooting onboarding issues; reduced average onboarding effort '
                        .'from approximately 10 to approximately 3 active hours and integration-support ticket '
                        .'volume by approximately 30%.',
                ],
            ],
            [
                'employer_name' => 'Pearson Online Learning Services',
                'display_title' => 'Data & Analytics Lead Developer',
                'role' => $pearsonRole,
                'display_order' => 1,
                'bullets' => [
                    'Built Nexus, a full-stack React/Node.js analytics platform that unified BigQuery and Salesforce '
                        .'data behind a single API layer and replaced manual export-and-compile reporting with live '
                        .'dashboards, reducing reporting time by 85%, saving approximately 20+ hours per week across '
                        .'teams, and providing KPI visibility across $25M+ in annual marketing spend.',
                    'Collaborated on a Salesforce migration and BigQuery data warehouse implementation, helping '
                        .'transition legacy MS SQL Server reporting systems while maintaining data integrity.',
                    'Built a centralized, multi-user Marketing Budget & Forecast Hub with approval workflows to '
                        .'replace fragile shared-spreadsheet planning, saving internal teams approximately 10–15 '
                        .'hours per month.',
                    'Built the Executive Insights Dashboard, consolidating department performance, goals, and '
                        .'emerging issues into an interactive shared real-time view for leadership in place of '
                        .'separate departmental spreadsheets.',
                    'Partnered with marketing, finance, and engineering stakeholders to translate business '
                        .'requirements into scalable full-stack applications for analytics, forecasting, and '
                        .'operational reporting, integrating Salesforce, BigQuery, Google Analytics, Salesforce '
                        .'Marketing Cloud, and advertising-platform data into unified marketing reporting.',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, category: string}>
     */
    private static function skills(): array
    {
        return [
            ['name' => 'TypeScript', 'category' => 'build_technology'],
            ['name' => 'Node.js', 'category' => 'build_technology'],
            ['name' => 'API integration & design', 'category' => 'capability'],
            ['name' => 'Data pipelines', 'category' => 'capability'],
            ['name' => 'High-risk data remediation engineering', 'category' => 'capability'],
            ['name' => 'PostgreSQL', 'category' => 'build_technology'],
            ['name' => 'MySQL', 'category' => 'build_technology'],
            ['name' => 'React', 'category' => 'build_technology'],
            ['name' => 'BigQuery', 'category' => 'platform_integration'],
            ['name' => 'Google Cloud Functions', 'category' => 'platform_integration'],
            ['name' => 'Security-conscious engineering', 'category' => 'capability'],
            ['name' => 'Salesforce', 'category' => 'platform_integration'],
            ['name' => 'Express.js', 'category' => 'build_technology'],
            ['name' => 'Analytics systems', 'category' => 'capability'],
            ['name' => 'Jira', 'category' => 'platform_integration'],
            ['name' => 'GitLab', 'category' => 'platform_integration'],
            ['name' => 'CI/CD', 'category' => 'practice'],
        ];
    }

    /**
     * @return array<int, array{institution: string, degree: string, field_of_study: string, start_year: int|null, end_year: int}>
     */
    private static function education(): array
    {
        return [
            ['institution' => 'Embry-Riddle Aeronautical University', 'degree' => 'Bachelor of Science', 'field_of_study' => 'Engineering Physics', 'start_year' => null, 'end_year' => 2004],
            ['institution' => 'University of Central Florida', 'degree' => 'Master of Science', 'field_of_study' => 'Optics', 'start_year' => null, 'end_year' => 2009],
        ];
    }
}
