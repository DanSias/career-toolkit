<?php

namespace App\Enums;

/**
 * How well a JobAnalysisFinding is covered by the canonical candidate
 * dataset supplied to one JobMatch run — an aggregate, per-finding
 * judgment, independent of any single CareerFactMatch/EducationMatch's
 * own `relationship`.
 *
 * `NoEvidence` and `NotAssessable` are deliberately NOT the same claim,
 * and neither is a claim about the candidate:
 *
 * - `NoEvidence` means this finding IS the kind of thing a CareerFact or
 *   Education row could evidence, and none currently does. It is a
 *   statement about dataset coverage, never "the candidate can't do
 *   this" or "the candidate is unqualified."
 * - `NotAssessable` means this finding falls outside what this matcher
 *   is authorized to determine from the canonical career-history and
 *   education evidence domain at all — e.g. visa/work-authorization
 *   status, current location, relocation willingness, travel
 *   willingness, salary preference, start-date availability. No amount
 *   of additional CareerFact data would ever change this classification
 *   for these categories of finding.
 *
 * See docs/job-match-contract.md for the full definitions and worked
 * examples.
 */
enum JobMatchCoverage: string
{
    case Supported = 'supported';
    case Partial = 'partial';
    case NoEvidence = 'no_evidence';
    case NotAssessable = 'not_assessable';
}
