// Single-use browser lifecycle for one inspection. No pooling, no
// persistent profile, no cookie/session persistence — a fresh,
// isolated context per inspection, closed reliably even on error. See
// README.md "Browser lifecycle".
import { chromium } from 'playwright';

const NAVIGATION_TIMEOUT_MS = 45_000;
const NETWORK_IDLE_TIMEOUT_MS = 15_000;
const SPA_SETTLE_MS = 1_500;

/**
 * Launches a browser, opens one page, navigates to url, and hands the
 * page to fn — guaranteeing the browser is closed afterward regardless
 * of whether fn throws. fn receives navigation timing/diagnostics
 * alongside the page so callers don't need to instrument navigation
 * themselves.
 *
 * @template T
 * @param {string} url
 * @param {(page: import('playwright').Page, nav: {finalUrl: string, navigationMs: number, warnings: string[]}) => Promise<T>} fn
 * @returns {Promise<T>}
 */
export async function withInspectionPage(url, fn) {
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({
            viewport: { width: 1400, height: 1000 },
        });
        try {
            const page = await context.newPage();
            const warnings = [];

            const startedAt = Date.now();
            await page.goto(url, {
                waitUntil: 'domcontentloaded',
                timeout: NAVIGATION_TIMEOUT_MS,
            });
            try {
                await page.waitForLoadState('networkidle', {
                    timeout: NETWORK_IDLE_TIMEOUT_MS,
                });
            } catch {
                warnings.push('networkidle_timeout');
            }
            // Give SPA-rendered forms (e.g. Greenhouse's React app) a brief
            // extra moment to finish rendering after DOMContentLoaded.
            await page.waitForTimeout(SPA_SETTLE_MS);
            const navigationMs = Date.now() - startedAt;

            return await fn(page, {
                finalUrl: page.url(),
                navigationMs,
                warnings,
            });
        } finally {
            await context.close();
        }
    } finally {
        await browser.close();
    }
}
