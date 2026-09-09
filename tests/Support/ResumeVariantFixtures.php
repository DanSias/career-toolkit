<?php

namespace Tests\Support;

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Employer;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;

/**
 * Shared, self-consistent fixtures for ResumeVariant tests: a small
 * candidate side (CareerProfile, one Employer/Role/Project, CareerFacts
 * with real attribution and Skills, Education) and a small job side
 * (JobAnalysis with an atomic technology finding shaped for
 * TargetTerminologyBuilder's extraction, plus a responsibility finding)
 * plus a JobMatch tying them together — so tests aren't each
 * hand-rolling a slightly different graph.
 */
final class ResumeVariantFixtures
{
    /**
     * @return array{profile: CareerProfile, employer: Employer, role: Role, project: Project, factAws: CareerFact, awsSkill: Skill, factIndependent: CareerFact, factPrivate: CareerFact, education: Education, independentProject: Project, independentProjectSkill: Skill, factIndependentProject: CareerFact}
     */
    public static function candidate(): array
    {
        $profile = CareerProfile::factory()->create();
        $employer = Employer::factory()->create(['career_profile_id' => $profile->id, 'name' => 'RocketGate']);
        $role = Role::factory()->create([
            'employer_id' => $employer->id,
            'title' => 'Senior Software Engineer / Platform Lead',
            'start_year' => 2021,
            'start_month' => 1,
            'end_year' => null,
            'end_month' => null,
        ]);
        $project = Project::factory()->create(['role_id' => $role->id, 'name' => 'Transaction Toolkit']);

        $awsSkill = Skill::factory()->create(['career_profile_id' => $profile->id, 'name' => 'AWS']);

        $factAws = CareerFact::factory()->create([
            'career_profile_id' => $profile->id,
            'key' => 'fixture-aws-deployment',
            'statement' => 'Built cloud-hosted deployment workflows and CI/CD pipelines on AWS.',
            'attributable_type' => (new Project)->getMorphClass(),
            'attributable_id' => $project->id,
            'visibility' => Visibility::Public,
        ]);
        $factAws->skills()->attach($awsSkill);

        $factIndependent = CareerFact::factory()->create([
            'career_profile_id' => $profile->id,
            'key' => 'fixture-independent-implementation',
            'statement' => 'Independently implemented the technical solution from team-provided requirements.',
            'attributable_type' => (new Role)->getMorphClass(),
            'attributable_id' => $role->id,
            'visibility' => Visibility::Restricted,
        ]);

        $factPrivate = CareerFact::factory()->create([
            'career_profile_id' => $profile->id,
            'key' => 'fixture-merchant-confidential-figure',
            'statement' => 'Corrected 24,589 transaction records for one merchant, 1,780 approved users.',
            'attributable_type' => (new Project)->getMorphClass(),
            'attributable_id' => $project->id,
            'visibility' => Visibility::Private,
        ]);

        $education = Education::factory()->create([
            'career_profile_id' => $profile->id,
            'degree' => 'Bachelor of Science',
            'field_of_study' => 'Computer Science',
        ]);

        // An independent/personal project — no Employer, no Role,
        // owned directly by $profile — for Selected Projects tests.
        // See docs/domain-model.md "ResumeVariant" -> "Selected
        // Projects".
        $independentProject = Project::factory()->create([
            'role_id' => null,
            'career_profile_id' => $profile->id,
            'name' => 'Well Prompted',
            'live_url' => 'https://wellprompted.example.dev',
            'repository_url' => 'https://github.com/example/well-prompted',
        ]);
        $independentProjectSkill = Skill::factory()->create(['career_profile_id' => $profile->id, 'name' => 'Prisma']);
        $factIndependentProject = CareerFact::factory()->create([
            'career_profile_id' => $profile->id,
            'key' => 'fixture-well-prompted-what-it-is',
            'statement' => 'Built a structured prompt library for reusable AI-assisted development workflows.',
            'attributable_type' => (new Project)->getMorphClass(),
            'attributable_id' => $independentProject->id,
            'visibility' => Visibility::Public,
        ]);
        $factIndependentProject->skills()->attach($independentProjectSkill);

        return compact(
            'profile', 'employer', 'role', 'project', 'factAws', 'awsSkill', 'factIndependent', 'factPrivate', 'education',
            'independentProject', 'independentProjectSkill', 'factIndependentProject',
        );
    }

    /**
     * @return array{analysis: JobAnalysis, azureFinding: JobAnalysisFinding, ownershipFinding: JobAnalysisFinding}
     */
    public static function job(): array
    {
        $analysis = JobAnalysis::factory()->create();

        $azureFinding = JobAnalysisFinding::factory()->create([
            'job_analysis_id' => $analysis->id,
            'category' => JobAnalysisFindingCategory::Technology,
            'label' => 'azure',
            'statement' => 'Azure is a cloud platform relevant to the role.',
            'requirement_strength' => JobAnalysisRequirementStrength::Required,
            'emphasis' => JobAnalysisEmphasis::High,
        ]);

        $ownershipFinding = JobAnalysisFinding::factory()->create([
            'job_analysis_id' => $analysis->id,
            'category' => JobAnalysisFindingCategory::Responsibility,
            'statement' => 'Own production systems end to end from team-defined requirements.',
            'requirement_strength' => JobAnalysisRequirementStrength::Required,
            'emphasis' => JobAnalysisEmphasis::Normal,
        ]);

        return compact('analysis', 'azureFinding', 'ownershipFinding');
    }

    /**
     * A JobMatch citing factAws as `transferable` for the Azure finding
     * (so direct_evidence_exists is false for "Azure" but real adjacent
     * evidence exists) and factIndependent as `direct` for the
     * ownership finding (so direct_evidence_exists is true for
     * "Independently implemented..." — though that finding isn't a
     * technology finding, it exercises the direct-evidence-authorization
     * path used elsewhere).
     */
    public static function jobMatch(
        CareerProfile $profile,
        JobAnalysis $analysis,
        JobAnalysisFinding $azureFinding,
        JobAnalysisFinding $ownershipFinding,
        CareerFact $factAws,
        CareerFact $factIndependent,
    ): JobMatch {
        $match = JobMatch::factory()->create([
            'career_profile_id' => $profile->id,
            'job_analysis_id' => $analysis->id,
        ]);

        $azureMatchFinding = $match->findings()->create([
            'job_analysis_finding_id' => $azureFinding->id,
            'coverage' => JobMatchCoverage::Partial,
            'coverage_rationale' => 'AWS deployment evidence exists but not Azure specifically.',
        ]);
        $azureMatchFinding->careerFactMatches()->create([
            'career_fact_id' => $factAws->id,
            'relationship' => MatchRelationship::Transferable,
            'rationale' => 'AWS deployment work is adjacent to Azure.',
        ]);

        $ownershipMatchFinding = $match->findings()->create([
            'job_analysis_finding_id' => $ownershipFinding->id,
            'coverage' => JobMatchCoverage::Supported,
            'coverage_rationale' => 'Directly demonstrated by independent implementation.',
        ]);
        $ownershipMatchFinding->careerFactMatches()->create([
            'career_fact_id' => $factIndependent->id,
            'relationship' => MatchRelationship::Direct,
            'rationale' => 'Independently implemented the solution from team requirements.',
        ]);

        return $match->fresh();
    }
}
