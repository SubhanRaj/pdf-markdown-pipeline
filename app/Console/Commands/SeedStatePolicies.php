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
 * One-off/reusable import: seeds Document + RuleSet(kind=policy) rows for a folder of
 * already-downloaded state policy PDFs, so they show up in the UI ready for Convert to
 * Markdown / OCR without going through the upload form one file at a time.
 */
class SeedStatePolicies extends Command
{
    protected $signature = 'policies:seed
        {--path=/home/subhan/Excise policies of states : Directory of PDF files to import}
        {--dept=excise : Department slug (must have level=department_level)}';

    protected $description = 'Seed state excise/export policy PDFs from a local directory into RuleSet + Document records';

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

        $user = User::orderBy('id')->first();

        if (! $user) {
            $this->error('No user found to attribute the import to.');

            return self::FAILURE;
        }

        $files = collect(glob($path.'/*.pdf'))
            ->filter(fn ($f) => ! str_starts_with(basename($f), '._'));

        if ($files->isEmpty()) {
            $this->warn('No PDF files found.');

            return self::SUCCESS;
        }

        foreach ($files as $file) {
            $this->importOne($file, $department, $user);
        }

        return self::SUCCESS;
    }

    private function importOne(string $file, Department $department, User $user): void
    {
        $filename = basename($file);

        // Common misspellings/abbreviations seen in real downloaded filenames that don't
        // literally contain the RuleSet::STATES spelling.
        $aliases = [
            'J&K'         => 'Jammu and Kashmir',
            'Chhatisgarh' => 'Chhattisgarh',
        ];

        $state = collect(RuleSet::STATES)->first(fn ($s) => stripos($filename, $s) !== false)
            ?? collect($aliases)->first(fn ($full, $alias) => stripos($filename, $alias) !== false, null);

        if (! $state) {
            $this->warn("Skipped (no matching state): {$filename}");

            return;
        }

        $policyType = match (true) {
            str_contains(strtolower($filename), 'export') => 'export_policy',
            str_contains(strtolower($filename), 'import') => 'import_policy',
            str_contains(strtolower($filename), 'cane')   => 'cane_policy',
            str_contains(strtolower($filename), 'sugar')  => 'sugar_policy',
            default                                        => 'excise_policy',
        };

        $isBarPolicy = str_contains(strtolower($filename), 'bar');
        $title       = $isBarPolicy
            ? "Bar Policy {$state}"
            : RuleSet::POLICY_TYPES[$policyType]." {$state}";

        if (Document::where('original_filename', $filename)->exists()) {
            $this->line("Skipped (already imported): {$filename}");

            return;
        }

        DB::transaction(function () use ($file, $filename, $department, $user, $state, $policyType, $title) {
            $ruleSet = RuleSet::firstOrCreate(
                [
                    'department_id' => $department->id,
                    'kind'          => 'policy',
                    'state'         => $state,
                    'policy_type'   => $policyType,
                ],
                [
                    'name' => $title,
                    'slug' => RuleSet::uniqueSlugForDepartment($title, $department->id),
                ]
            );

            $vaultDir = implode('/', [
                'document_vault', $department->level, $department->slug, 'rules', $ruleSet->slug,
            ]);

            $slug      = Document::uniqueSlugForRuleSet($title, $ruleSet->id);
            $timestamp = now()->format('YmdHis');
            $storedAs  = "{$vaultDir}/{$slug}_{$timestamp}.pdf";

            Storage::disk('public')->put($storedAs, file_get_contents($file));

            $document = Document::create([
                'department_id'      => $department->id,
                'rule_set_id'        => $ruleSet->id,
                'user_id'            => $user->id,
                'title'              => $title,
                'slug'               => $slug,
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
                'note'        => 'Seeded from local state-policy batch import (policies:seed).',
            ]);
        });

        $this->info("Imported: {$filename} → {$title}");
    }
}
