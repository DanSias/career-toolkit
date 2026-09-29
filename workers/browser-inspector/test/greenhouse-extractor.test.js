// Deterministic tests against saved fixture HTML — never a live
// network dependency. Requires Playwright's Chromium browser to be
// installed (bundled in the worker's own Docker image; see
// README.md "Tests" for the local-machine prerequisite otherwise).
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';
import { detect, extract } from '../src/extractors/greenhouse.js';
import { LabelSource, ExtractionSource } from '../src/result.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const greenhouseFixture = readFileSync(
    path.join(__dirname, 'fixtures/greenhouse-form.html'),
    'utf8',
);
const unsupportedFixture = readFileSync(
    path.join(__dirname, 'fixtures/unsupported-page.html'),
    'utf8',
);

describe('greenhouse extractor', () => {
    let browser;
    let page;

    before(async () => {
        browser = await chromium.launch({ headless: true });
        page = await browser.newPage();
    });

    after(async () => {
        await browser.close();
    });

    test('detects a Greenhouse application via its CDN asset reference', async () => {
        await page.setContent(greenhouseFixture);
        assert.equal(await detect(page), true);
    });

    test('does not detect an ordinary page as Greenhouse', async () => {
        await page.setContent(unsupportedFixture);
        assert.equal(await detect(page), false);
    });

    describe('field extraction', () => {
        let fields;
        let diagnostics;

        before(async () => {
            await page.setContent(greenhouseFixture);
            const result = await extract(page);
            fields = result.fields;
            diagnostics = result.diagnostics;
        });

        function byExternalId(id) {
            const f = fields.find((x) => x.external_field_id === id);
            assert.ok(f, `expected a field with external_field_id "${id}"`);
            return f;
        }

        test('assigns explicit, zero-based, document-order position — not insertion order', () => {
            const positions = fields.map((f) => f.position);
            assert.deepEqual(
                positions,
                fields.map((_, i) => i),
            );
            assert.equal(byExternalId('first_name').position, 0);
            assert.equal(byExternalId('last_name').position, 1);
        });

        test('resolves a native label[for] association', () => {
            const f = byExternalId('first_name');
            assert.equal(f.raw_label, 'First Name*');
            assert.equal(f.label_source, LabelSource.DomLabelFor);
            assert.equal(f.control_type, 'text');
        });

        test('resolves a wrapping <label> association', () => {
            const f = byExternalId('last_name');
            assert.equal(f.raw_label, 'Last Name*');
            assert.equal(f.label_source, LabelSource.DomWrappingLabel);
        });

        test('resolves an aria-label association', () => {
            const f = byExternalId('summary');
            assert.equal(f.raw_label, 'Why do you want to work here?');
            assert.equal(f.label_source, LabelSource.DomAriaLabel);
            assert.equal(f.control_type, 'textarea');
        });

        test('resolves an aria-labelledby association', () => {
            const f = byExternalId('country');
            assert.equal(f.raw_label, 'Country*');
            assert.equal(f.label_source, LabelSource.DomAriaLabelledby);
        });

        test('resolves a placeholder-only association', () => {
            const f = byExternalId('linkedin_url');
            assert.equal(f.raw_label, 'LinkedIn URL');
            assert.equal(f.label_source, LabelSource.DomPlaceholder);
        });

        test('falls back to structural/proximity association for plain preceding text', () => {
            const f = byExternalId('portfolio_url');
            assert.equal(f.raw_label, 'Portfolio (optional)');
            assert.equal(f.label_source, LabelSource.InferredProximity);
        });

        test('honestly reports an unresolved label rather than guessing', () => {
            const f = byExternalId('unlabeled_field');
            assert.equal(f.raw_label, null);
            assert.equal(f.label_source, LabelSource.Unresolved);
        });

        test('never reports a Greenhouse custom-combobox widget\'s generic "Select..." prompt as a real label', () => {
            const f = byExternalId('relocation_value');
            assert.notEqual(f.raw_label, 'Select...');
            assert.equal(f.label_source, LabelSource.Unresolved);
        });

        test('required: true is derived from the required attribute', () => {
            assert.equal(byExternalId('first_name').required, true);
            assert.equal(byExternalId('resume').required, true);
        });

        test('required: false is a definitive signal, not an unknown one, for a native control missing the attribute', () => {
            assert.equal(byExternalId('phone').required, false);
        });

        test('required: null for a custom ARIA-role widget with no explicit signal', () => {
            const combobox = fields.find((f) => f.control_type === 'combobox');
            assert.ok(combobox);
            assert.equal(combobox.required, null);
            assert.equal(combobox.external_field_id, null);
            assert.equal(combobox.raw_label, 'Preferred Location');
        });

        test('extracts select options in source order, excluding the placeholder option', () => {
            const f = byExternalId('country');
            assert.deepEqual(f.options, [
                'United States',
                'Canada',
                'United Kingdom',
            ]);
        });

        test('control types cover text/textarea/select/checkbox/file/radio', () => {
            assert.equal(byExternalId('first_name').control_type, 'text');
            assert.equal(byExternalId('summary').control_type, 'textarea');
            assert.equal(byExternalId('country').control_type, 'select');
            assert.equal(byExternalId('subscribe').control_type, 'checkbox');
            assert.equal(byExternalId('resume').control_type, 'file');
            assert.equal(byExternalId('gender_male').control_type, 'radio');
        });

        test('extracts section context from an enclosing fieldset/legend', () => {
            assert.equal(byExternalId('gender_male').section, 'Gender');
            assert.equal(byExternalId('gender_female').section, 'Gender');
        });

        test('leaves section null when no fieldset or preceding heading applies', () => {
            assert.equal(byExternalId('first_name').section, null);
        });

        test('excludes hidden fields and buttons from the field contract entirely', () => {
            assert.equal(
                fields.find((f) => f.external_field_id === 'csrf_token'),
                undefined,
            );
            assert.ok(
                !fields.some(
                    (f) =>
                        f.control_type === 'button' ||
                        f.control_type === 'submit',
                ),
            );
        });

        test('marks extraction_source as both when the DOM-resolved label is confirmed by the accessibility tree', () => {
            const f = byExternalId('first_name');
            assert.equal(f.extraction_source, ExtractionSource.Both);
        });

        test('reports field_count and unresolved_label_count diagnostics', () => {
            assert.equal(diagnostics.field_count, fields.length);
            assert.equal(diagnostics.unresolved_label_count, 2);
            assert.equal(diagnostics.hidden_field_count, 1);
            assert.equal(diagnostics.button_count, 2);
        });
    });
});
