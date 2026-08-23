<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off import: seeds previous-year policy documents (RuleSet container_id set + Document)
 * under the existing "Excise Policy Uttar Pradesh" container from a folder of yearly PDFs
 * named "..._2021-22_...pdf" etc. Mirrors policies:seed but for policy documents under a
 * container instead of top-level containers, reusing PolicyDocumentController's
 * chronological-supersession logic so this never overwrites the real current document.
 */
class SeedUpExcisePolicyDocuments extends Command
{
    protected $signature = 'policies:seed-up-policy-documents
        {--path= : Directory of PDF files to import}
        {--dept=excise : Department slug (must have level=department_level)}';

    protected $description = 'Seed previous-year UP Excise Policy PDFs as policy documents under the existing UP Excise Policy container';

    public function handle(): int
    {
        $path = $this->option('path');

        if (! is_dir($path)) {
            $this->error("Directory not found: {$path}");

            return self::FAILURE;
        }

        $department = Department::where('slug', $this->option('dept'))
            ->where('level', 'department_level')
            ->first();

        if (! $department) {
            $this->error("No department_level department with slug '{$this->option('dept')}' found.");

            return self::FAILURE;
        }

        $container = RuleSet::policyContainers()
            ->where('department_id', $department->id)
            ->where('state', RuleSet::DEFAULT_STATE)
            ->where('policy_type', 'excise_policy')
            ->first();

        if (! $container) {
            $this->error('No UP Excise Policy container found — create it first via the Policy > Uttar Pradesh flow.');

            return self::FAILURE;
        }

        $user = User::orderBy('id')->first();

        if (! $user) {
            $this->error('No user found to attribute the import to.');

            return self::FAILURE;
        }

        $files = collect(glob($path.'/*.pdf'))
            ->filter(fn ($f) => ! str_starts_with(basename($f), '._'))
            ->map(function ($file) {
                preg_match('/(\d{4})-(\d{2})/', basename($file), $m);

                return $m ? ['file' => $file, 'startYear' => (int) $m[1]] : null;
            })
            ->filter()
            ->sortBy('startYear')
            ->values();

        if ($files->isEmpty()) {
            $this->warn('No PDF files with a recognizable "YYYY-YY" timeframe in the filename found.');

            return self::SUCCESS;
        }

        $previousId = null;

        foreach ($files as $entry) {
            $previousId = $this->importOne($entry['file'], $entry['startYear'], $department, $container, $user, $previousId);
        }

        // Chain the real current policy document back to the newest imported year,
        // completing the supersession history (it was created independently, before this import).
        $currentPolicyDoc = RuleSet::currentPolicy()->where('container_id', $container->id)->first();
        if ($currentPolicyDoc && ! $currentPolicyDoc->previous_policy_id && $previousId) {
            $currentPolicyDoc->update(['previous_policy_id' => $previousId]);
        }

        return self::SUCCESS;
    }

    private function importOne(string $file, int $startYear, Department $department, RuleSet $container, User $user, ?int $previousId): ?int
    {
        $filename = basename($file);
        $endYear  = $startYear + 1;
        $label    = $startYear.'-'.substr((string) $endYear, 2);
        $name     = "{$container->name} {$label}";

        if (Document::where('original_filename', $filename)->exists()) {
            $this->line("Skipped (already imported): {$filename}");

            return $previousId;
        }

        $newId = null;

        DB::transaction(function () use (
            $file, $filename, $department, $container, $user, $startYear, $endYear, $name, $previousId, &$newId
        ) {
            $slug = RuleSet::uniqueSlugForDepartment($name, $department->id);

            $policyDoc = RuleSet::create([
                'department_id'        => $department->id,
                'name'                 => $name,
                'slug'                 => $slug,
                'kind'                 => 'policy',
                'state'                => $container->state,
                'policy_type'          => $container->policy_type,
                'container_id'         => $container->id,
                'effective_start_date' => "{$startYear}-04-01",
                'effective_end_date'   => "{$endYear}-03-31",
                'policy_status'        => 'superseded',
                'previous_policy_id'   => $previousId,
            ]);

            $vaultDir = implode('/', [
                'document_vault', $department->level, $department->slug, 'rules', $policyDoc->slug,
            ]);
            $docSlug   = Document::uniqueSlugForRuleSet($name, $policyDoc->id);
            $storedAs  = "{$vaultDir}/{$docSlug}_".now()->format('YmdHis').'.pdf';

            Storage::disk('public')->put($storedAs, file_get_contents($file));

            $document = Document::create([
                'department_id'      => $department->id,
                'rule_set_id'        => $policyDoc->id,
                'user_id'            => $user->id,
                'title'              => $name,
                'slug'               => $docSlug,
                'document_type'      => 'policy',
                'original_filename'  => $filename,
                'original_pdf_path'  => $storedAs,
                'vault_path'         => $vaultDir,
                'status'             => 'uploaded',
                'visibility'         => 'public',
            ]);

            DocumentStatusHistory::create([
                'document_id' => $document->id,
                'actor_id'    => $user->id,
                'from_status' => null,
                'to_status'   => 'uploaded',
                'note'        => 'Seeded from local UP Excise Policy batch import (policies:seed-up-policy-documents).',
            ]);

            $newId = $policyDoc->id;
        });

        $this->info("Imported: {$filename} → {$name}");

        return $newId ?? $previousId;
    }
}
