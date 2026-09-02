<?php

namespace App\Enums;

/**
 * The 13 categories established by the job-analysis design report and its
 * refinement pass, validated against the five-posting design corpus.
 * Deliberately not split further per-category into separate tables — see
 * docs/domain-model.md "JobAnalysis".
 */
enum JobAnalysisFindingCategory: string
{
    case Responsibility = 'responsibility';
    case RequiredQualification = 'required_qualification';
    case PreferredQualification = 'preferred_qualification';
    case Technology = 'technology';
    case Capability = 'capability';
    case DomainKnowledge = 'domain_knowledge';
    case SuccessMeasure = 'success_measure';
    case CultureSignal = 'culture_signal';
    case NegativeFitSignal = 'negative_fit_signal';
    case ApplicationRequest = 'application_request';
    case WorkArrangement = 'work_arrangement';
    case Travel = 'travel';
    case Authorization = 'authorization';
}
