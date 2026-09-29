// Greenhouse detection and field extraction — the only ATS Phase 2
// supports. Adapted from the disposable investigation PoC's proven
// domExtract() (~/career-toolkit-browser-poc on the AI box), which
// successfully extracted a real 51-field Anthropic application form.
//
// Two deliberate departures from the PoC, both evidence-grounded — see
// README.md "Extraction strategy" for the full reasoning:
//
// 1. Required-state uses ONLY the `required` attribute / aria-required
//    — the PoC's asterisk-in-label fallback is dropped. Every
//    asterisk-marked required field observed in the real Anthropic
//    capture also carried the real `required` attribute, so the
//    asterisk signal was never independently necessary; keeping an
//    unverifiable heuristic in production would risk a false positive
//    on a field that merely LOOKS required.
//
// 2. A genuine whole-page ARIA cross-check (a single ariaSnapshot()
//    call, not one per field) confirms DOM-resolved labels —
//    extraction_source becomes 'both' when the DOM-derived label also
//    appears as an accessible name somewhere on the page, 'dom'
//    otherwise. ARIA is NOT used as an independent label-recovery path
//    for DOM-unresolved fields: no real Greenhouse or Lever field
//    observed during investigation was rescued by ARIA after every DOM
//    tier (including proximity) had already failed, and inventing a
//    label_source for that unobserved case would misrepresent
//    provenance rather than honestly report it. See the Lever
//    university-selector finding in the browser-automation
//    investigation for the one confirmed case where DOM and ARIA both
//    failed to name a control.
import { LabelSource, ExtractionSource } from '../result.js';

/**
 * @param {import('playwright').Page} page
 * @returns {Promise<boolean>}
 */
export async function detect(page) {
    const hostname = new URL(page.url()).hostname.toLowerCase();
    if (/(^|\.)greenhouse\.io$/.test(hostname)) return true;

    // Secondary signal for a Greenhouse application embedded under a
    // company's own custom domain: Greenhouse's own JS/CSS assets are
    // always served from a greenhouse.io-owned CDN regardless of the
    // page's own domain — observed directly against the real Anthropic
    // capture (job-boards.cdn.greenhouse.io/assets/vendor-*.js).
    return page.evaluate(() => {
        const srcs = Array.from(
            document.querySelectorAll('script[src], link[href]'),
        ).map((el) => el.getAttribute('src') || el.getAttribute('href') || '');
        return srcs.some((src) => /greenhouse\.io/i.test(src));
    });
}

// Runs INSIDE the browser context via page.evaluate() — plain browser
// JS, no access to Node/Playwright APIs.
function domExtract() {
    function visible(el) {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return (
            r.width > 0 &&
            r.height > 0 &&
            cs.visibility !== 'hidden' &&
            cs.display !== 'none'
        );
    }

    function nearestSectionLabel(el) {
        let node = el;
        while (node && node !== document.body) {
            if (node.tagName === 'FIELDSET') {
                const legend = node.querySelector('legend');
                if (legend && legend.innerText.trim())
                    return legend.innerText.trim();
            }
            node = node.parentElement;
        }
        const headings = Array.from(
            document.querySelectorAll('h1,h2,h3,h4,[role="heading"]'),
        );
        const elTop = el.getBoundingClientRect().top + window.scrollY;
        let best = null;
        for (const h of headings) {
            const hTop = h.getBoundingClientRect().top + window.scrollY;
            if (hTop <= elTop) best = h;
        }
        return best ? best.innerText.trim() || null : null;
    }

    // A preceding-text element consumed as one field's proximity label
    // must never also be attributed to a later field — without this, two
    // structurally adjacent unlabeled controls (e.g. no readable content
    // between them) both walk back to and claim the SAME earlier text,
    // silently misattributing the second field's label. Found via a
    // real fixture-based test failure, not by inspection alone.
    const consumedProximitySources = new Set();

    function resolveLabel(el) {
        if (el.id) {
            const lbl = document.querySelector(
                `label[for="${CSS.escape(el.id)}"]`,
            );
            if (lbl && lbl.innerText.trim())
                return { text: lbl.innerText.trim(), source: 'dom_label_for' };
        }
        const wrapping = el.closest('label');
        if (wrapping && wrapping.innerText.trim())
            return {
                text: wrapping.innerText.trim(),
                source: 'dom_wrapping_label',
            };
        const ariaLabel = el.getAttribute('aria-label');
        if (ariaLabel && ariaLabel.trim())
            return { text: ariaLabel.trim(), source: 'dom_aria_label' };
        const labelledby = el.getAttribute('aria-labelledby');
        if (labelledby) {
            const parts = labelledby
                .split(/\s+/)
                .map((id) => document.getElementById(id)?.innerText?.trim())
                .filter(Boolean);
            if (parts.length)
                return { text: parts.join(' '), source: 'dom_aria_labelledby' };
        }
        if (el.placeholder && el.placeholder.trim())
            return { text: el.placeholder.trim(), source: 'dom_placeholder' };
        let sib = el.previousElementSibling;
        let hops = 0;
        while (sib && hops < 3) {
            const t = sib.innerText?.trim();
            // GREENHOUSE_GENERIC_PROMPT_TEXT: observed directly against a real,
            // live Greenhouse application (Anthropic, "Account Executive, AI
            // Native") — Greenhouse's custom combobox widget renders as a
            // labeled visible button plus a separate, unlabeled underlying
            // <input> that only holds the selected value. Without this check,
            // that second element's proximity fallback picks up the widget's
            // own generic "Select..." prompt text and misreports it as if it
            // were the field's real question — a genuine mislabel, not honest
            // uncertainty. The field's real question is already correctly
            // captured on the separate, properly-labeled element nearby; this
            // synthetic input is left honestly unresolved instead. See
            // README.md "Extraction strategy" for the full finding.
            // A generic-prompt match stops the search outright rather than
            // being skipped-past: it's evidence this element sits immediately
            // inside a widget's own internal structure, so text found by
            // continuing further back is more likely an unrelated, genuinely
            // wrong label (a different field's own label, in practice) than
            // this field's real one — which, when it exists, is already
            // captured correctly on a separate, properly-labeled element.
            if (t && /^select\.{2,3}$/i.test(t)) break;
            if (
                t &&
                t.length > 0 &&
                t.length < 200 &&
                !consumedProximitySources.has(sib)
            ) {
                consumedProximitySources.add(sib);
                return { text: t, source: 'inferred_proximity' };
            }
            sib = sib.previousElementSibling;
            hops++;
        }
        return { text: null, source: 'unresolved' };
    }

    function isRequired(el) {
        // aria-required, when explicitly present, is authoritative — it can
        // legitimately override a native control's own required IDL for
        // assistive-technology purposes.
        if (el.hasAttribute('aria-required')) {
            return el.getAttribute('aria-required') === 'true';
        }
        // Native input/textarea/select always expose a real, unambiguous
        // required IDL property — its absence-as-false is a definitive
        // negative signal per the HTML spec, never an unknown one.
        if ('required' in el) {
            return el.required === true;
        }
        // A custom ARIA-role widget (a non-native element) with no
        // aria-required at all has no required-state signal whatsoever —
        // this is the one genuinely unknown case, not merely unstated.
        return null;
    }

    function externalFieldId(el) {
        return el.id || el.name || el.getAttribute('data-testid') || null;
    }

    function cssPath(el) {
        if (el.id) return `#${CSS.escape(el.id)}`;
        if (el.name) return `[name="${CSS.escape(el.name)}"]`;
        if (el.getAttribute('data-testid'))
            return `[data-testid="${CSS.escape(el.getAttribute('data-testid'))}"]`;
        let node = el;
        const parts = [];
        for (let i = 0; i < 4 && node && node !== document.body; i++) {
            let idx = 1;
            let sib = node;
            while ((sib = sib.previousElementSibling))
                if (sib.tagName === node.tagName) idx++;
            parts.unshift(`${node.tagName.toLowerCase()}:nth-of-type(${idx})`);
            node = node.parentElement;
        }
        return parts.join(' > ');
    }

    const controlSelector = [
        'input:not([type="hidden"]):not([type="submit"]):not([type="button"])',
        'textarea',
        'select',
        '[role="radio"]',
        '[role="checkbox"]',
        '[role="combobox"]',
        '[role="listbox"]',
        '[role="switch"]',
    ].join(',');

    const fields = [];
    let hiddenFieldCount = 0;
    let buttonCount = 0;

    document
        .querySelectorAll('input[type="hidden"]')
        .forEach(() => hiddenFieldCount++);
    document
        .querySelectorAll(
            'button, [role="button"], input[type="submit"], input[type="button"]',
        )
        .forEach(() => buttonCount++);

    document.querySelectorAll(controlSelector).forEach((el) => {
        if (!visible(el)) return;

        const tag = el.tagName.toLowerCase();
        const controlType =
            el.getAttribute('type') || el.getAttribute('role') || tag;
        const { text: rawLabel, source: labelSource } = resolveLabel(el);

        let options = null;
        if (tag === 'select') {
            options = Array.from(el.options)
                .filter((o) => !o.disabled && o.value !== '')
                .map((o) => o.text.trim());
            if (options.length === 0) options = null;
        }

        fields.push({
            locator: cssPath(el),
            external_field_id: externalFieldId(el),
            raw_label: rawLabel,
            label_source: labelSource,
            control_type: controlType,
            required: isRequired(el),
            options,
            section: nearestSectionLabel(el),
        });
    });

    return { fields, hiddenFieldCount, buttonCount };
}

/**
 * @param {import('playwright').Page} page
 * @returns {Promise<{fields: object[], warnings: string[], diagnostics: object}>}
 */
export async function extract(page) {
    const {
        fields: rawFields,
        hiddenFieldCount,
        buttonCount,
    } = await page.evaluate(domExtract);

    // One whole-page ARIA snapshot to cross-check DOM-resolved labels —
    // never one call per field. See this module's own docblock.
    let ariaNames = new Set();
    const warnings = [];
    try {
        const ariaYaml = await page.locator('body').ariaSnapshot();
        for (const match of ariaYaml.matchAll(/"([^"]+)"/g)) {
            ariaNames.add(normalizeForComparison(match[1]));
        }
    } catch {
        warnings.push('aria_snapshot_unavailable');
    }

    let unresolvedCount = 0;
    const fields = rawFields.map((f, index) => {
        if (f.label_source === LabelSource.Unresolved) unresolvedCount++;

        let extractionSource = ExtractionSource.Dom;
        if (f.raw_label && ariaNames.has(normalizeForComparison(f.raw_label))) {
            extractionSource = ExtractionSource.Both;
        }

        return {
            position: index,
            external_field_id: f.external_field_id,
            raw_label: f.raw_label,
            label_source: f.label_source,
            control_type: f.control_type,
            required: f.required,
            options: f.options,
            section: f.section,
            extraction_source: extractionSource,
        };
    });

    return {
        fields,
        warnings,
        diagnostics: {
            field_count: fields.length,
            unresolved_label_count: unresolvedCount,
            hidden_field_count: hiddenFieldCount,
            button_count: buttonCount,
        },
    };
}

function normalizeForComparison(text) {
    return text.trim().toLowerCase();
}
