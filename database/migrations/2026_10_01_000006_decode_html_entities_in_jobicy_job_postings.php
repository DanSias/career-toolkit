<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No schema change — a one-time data correction. Jobicy's
     * companyName/jobTitle fields come through HTML-entity-encoded
     * (confirmed live — e.g. "hims &#038; hers"); App\Support\
     * JobDiscovery\Providers\JobicyDiscoveryProvider now decodes them
     * on ingestion, but `company`/`title` are write-once on an
     * existing JobPosting (App\Support\JobDiscovery\
     * IngestDiscoveredCandidate::updateExisting() never refreshes
     * either column), so the 2 real rows created before that fix
     * would otherwise carry the undecoded entity forever. Applies the
     * exact same decode used by the provider, only to jobicy rows,
     * only when an entity pattern is actually present.
     */
    public function up(): void
    {
        DB::table('job_postings')->where('discovery_source', 'jobicy')->get(['id', 'company', 'title'])
            ->each(function ($job) {
                $company = html_entity_decode($job->company, ENT_QUOTES | ENT_HTML5);
                $title = html_entity_decode($job->title, ENT_QUOTES | ENT_HTML5);

                if ($company !== $job->company || $title !== $job->title) {
                    DB::table('job_postings')->where('id', $job->id)->update([
                        'company' => $company,
                        'title' => $title,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op — re-encoding decoded text is not a
     * meaningful "reversal," and no schema changed.
     */
    public function down(): void {}
};
