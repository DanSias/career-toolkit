// Orchestrates one inspection: validate the URL, launch a browser,
// identify the ATS, extract fields if supported, and always return a
// structured result — never throws for an ordinary/expected outcome.
// This is the reusable boundary Phase 3 will call into; src/cli.js is
// only a thin wrapper around it. See README.md "Architecture boundary".
import {
    validateUrlStructure,
    resolvesToPrivateAddress,
} from './url-safety.js';
import { withInspectionPage } from './browser.js';
import * as greenhouse from './extractors/greenhouse.js';
import { succeeded, unsupported, failed, FailureCategory } from './result.js';

/**
 * @param {string} rawUrl
 * @returns {Promise<object>} one of the result.js shapes
 */
export async function inspect(rawUrl) {
    const startedAt = Date.now();

    const structural = validateUrlStructure(rawUrl);
    if (!structural.valid) {
        return failed({
            failureCategory: FailureCategory.UnexpectedError,
            failureMessage: `Rejected before navigation: ${structural.reason}`,
            diagnostics: baseDiagnostics({ startedAt }),
        });
    }

    const dnsCheck = await resolvesToPrivateAddress(structural.url);
    if (!dnsCheck.safe) {
        return failed({
            failureCategory: FailureCategory.UnexpectedError,
            failureMessage: `Rejected before navigation: ${dnsCheck.reason}`,
            diagnostics: baseDiagnostics({ startedAt }),
        });
    }

    try {
        return await withInspectionPage(
            structural.url.toString(),
            async (page, nav) => {
                const isGreenhouse = await greenhouse.detect(page);

                if (!isGreenhouse) {
                    return unsupported({
                        requestedUrl: rawUrl,
                        finalUrl: nav.finalUrl,
                        warnings: nav.warnings,
                        diagnostics: baseDiagnostics({
                            startedAt,
                            navigationMs: nav.navigationMs,
                            pageTitle: await page.title(),
                        }),
                    });
                }

                const extractionStartedAt = Date.now();
                const { fields, warnings, diagnostics } =
                    await greenhouse.extract(page);
                const extractionMs = Date.now() - extractionStartedAt;

                return succeeded({
                    ats: 'greenhouse',
                    requestedUrl: rawUrl,
                    finalUrl: nav.finalUrl,
                    fields,
                    warnings: [...nav.warnings, ...warnings],
                    diagnostics: baseDiagnostics({
                        startedAt,
                        navigationMs: nav.navigationMs,
                        extractionMs,
                        pageTitle: await page.title(),
                        ...diagnostics,
                    }),
                });
            },
        );
    } catch (error) {
        const category =
            error?.name === 'TimeoutError'
                ? FailureCategory.NavigationTimeout
                : FailureCategory.UnexpectedError;

        return failed({
            failureCategory: category,
            failureMessage: String(error?.message ?? error),
            diagnostics: baseDiagnostics({ startedAt }),
        });
    }
}

/**
 * Small, structured, non-content diagnostics only — never a raw DOM/
 * accessibility-tree/page-text dump, never cookies or storage. See
 * docs/domain-model.md "WorkflowRun, WorkflowStep, and AgentRun" for
 * the same policy on the Career Toolkit side (AgentRun.result_summary).
 */
function baseDiagnostics({
    startedAt,
    navigationMs,
    extractionMs,
    pageTitle,
    ...rest
}) {
    return {
        total_ms: Date.now() - startedAt,
        navigation_ms: navigationMs ?? null,
        extraction_ms: extractionMs ?? null,
        page_title: pageTitle ?? null,
        timestamp: new Date().toISOString(),
        ...rest,
    };
}
