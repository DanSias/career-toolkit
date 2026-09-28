import { Head } from '@inertiajs/react';
import AppShell from '@/layouts/app-shell';

type ResumeVariantPreviewProps = {
    resumeVariant: { id: number };
    pdfUrl: string;
};

/**
 * Embeds the actual generated PDF (via the browser's own native PDF
 * viewer, no PDF.js) rather than an independently-paginated HTML
 * rendering — see App\Http\Controllers\ResumeVariantPreviewController's
 * own docblock for why. Page boundaries shown here are therefore always
 * byte-identical to "Download PDF", since `pdfUrl` points at the exact
 * same generator/route, only with an inline disposition.
 */
export default function ResumeVariantPreview({
    resumeVariant,
    pdfUrl,
}: ResumeVariantPreviewProps) {
    return (
        <AppShell>
            <Head title={`Resume Preview #${resumeVariant.id}`} />

            <div className="rounded-lg bg-neutral-200 p-4 dark:bg-neutral-800">
                <iframe
                    src={pdfUrl}
                    title="Resume PDF preview"
                    className="h-[85vh] w-full rounded border border-neutral-300 bg-white dark:border-neutral-700"
                />
            </div>
        </AppShell>
    );
}
