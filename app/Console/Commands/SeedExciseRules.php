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
 * One-off/reusable import: seeds RuleSet(kind=rules) + Document rows from a folder tree of
 * already-downloaded rule-book PDFs — one subfolder per rule subject, one RuleSet per subfolder.
 *
 * Within a subfolder, files are split into a root "base rules" document and its amendments
 * (parent_id chain) so the UI doesn't dump everything flat: the earliest-dated file (year parsed
 * from the filename; undated files sort first, e.g. a "(Misc)" consolidated copy) becomes the
 * root; everything else in that subfolder becomes an amendment attached to it. Filenames that
 * are clearly not part of the rule series itself (circulars, checklists) are imported as
 * standalone documents instead, not attached to the root.
 */
class SeedExciseRules extends Command
{
    protected $signature = 'rules:seed
        {--path=/home/subhan/Excise Rule Book/All Rules Files : Directory of subject subfolders to import}
        {--dept=excise : Department slug (must have level=department_level)}';

    protected $description = 'Seed excise rule-book PDFs (rules + amendments) from a local directory tree into RuleSet + Document records';

    /** Subfolder name => RuleSet name. Strips the "Rules " prefix; a couple of special cases. */
    private const RULE_SET_NAMES = [
        'Rules Bar'                          => 'Bar',
        'Rules Beer (Retail)'                => 'Beer (Retail)',
        'Rules Bhang Retail'                 => 'Bhang Retail',
        'Rules Bottling Foreign Liquor'      => 'Bottling Foreign Liquor',
        'Rules Breweries Establishment'      => 'Breweries Establishment',
        'Rules BWFL-2'                       => 'BWFL-2',
        'Rules Country Liquor Bottling'      => 'Country Liquor Bottling',
        'Rules Country Liquor (Retail)'      => 'Country Liquor (Retail)',
        'Rules Country Liquor (Wholesale)'   => 'Country Liquor (Wholesale)',
        'Rules Distilleries'                 => 'Distilleries',
        'Rules FL9-9A'                       => 'FL9-9A',
        'Rules Foreign Liquor (Retail)'      => 'Foreign Liquor (Retail)',
        'Rules Foreign Liquor (Wholesale) 2' => 'Foreign Liquor (Wholesale)',
        'Rules Model Shops'                  => 'Model Shops',
        'Rules Number and Location'          => 'Number and Location',
        'Rules Premium Retail Vends'         => 'Premium Retail Vends',
        'Rules Winery Establishment'         => 'Winery Establishment',
        'UP Excise Act'                      => 'UP Excise Act',
    ];

    public function handle(): int
    {
        $basePath = $this->option('path');

        if (! is_dir($basePath)) {
            $this->error("Directory not found: {$basePath}");

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

        foreach (self::RULE_SET_NAMES as $folder => $ruleSetName) {
            $dir = $basePath.'/'.$folder;

            if (! is_dir($dir)) {
                $this->warn("Folder not found, skipped: {$folder}");

                continue;
            }

            $this->importFolder($dir, $ruleSetName, $department, $user);
        }

        return self::SUCCESS;
    }

    private function importFolder(string $dir, string $ruleSetName, Department $department, User $user): void
    {
        $files = collect(glob($dir.'/*.[pP][dD][fF]'))
            ->filter(fn ($f) => ! str_starts_with(basename($f), '._'))
            ->values();

        if ($files->isEmpty()) {
            $this->warn("No PDFs in: {$ruleSetName}");

            return;
        }

        $ruleSet = RuleSet::firstOrCreate(
            ['department_id' => $department->id, 'kind' => 'rules', 'name' => $ruleSetName],
            ['slug' => RuleSet::uniqueSlugForDepartment($ruleSetName, $department->id)]
        );

        // Split off circulars/checklists — standalone documents, never part of the amendment chain.
        $standalone = $files->filter(fn ($f) => preg_match('/circular|check\s*list/i', basename($f)));
        $series     = $files->diff($standalone)
            ->sortBy(fn ($f) => sprintf('%04d-%d', $this->parseYear(basename($f)), str_contains(strtolower(basename($f)), 'amendment') ? 1 : 0))
            ->values();

        $root    = $series->shift();
        $rootDoc = $root ? $this->importOne($root, $ruleSet, $department, $user, 'rule', null) : null;

        foreach ($series as $file) {
            $this->importOne($file, $ruleSet, $department, $user, 'rule_amendment', $rootDoc?->id);
        }

        foreach ($standalone as $file) {
            $this->importOne($file, $ruleSet, $department, $user, 'other', null);
        }
    }

    private function parseYear(string $filename): int
    {
        return preg_match('/\b(19|20)\d{2}\b/', $filename, $m) ? (int) $m[0] : 0;
    }

    private function parseAmendmentNumber(string $filename): ?int
    {
        return preg_match('/(\d+)(?:st|nd|rd|th)\s+amendment/i', $filename, $m) ? (int) $m[1] : null;
    }

    private function importOne(
        string $file,
        RuleSet $ruleSet,
        Department $department,
        User $user,
        string $documentType,
        ?int $parentId
    ): ?Document {
        $filename = basename($file);

        $existing = Document::where('rule_set_id', $ruleSet->id)
            ->where('original_filename', $filename)
            ->first();

        if ($existing) {
            $this->line("Skipped (already imported): {$filename}");

            return $existing;
        }

        $language = match (true) {
            str_contains(strtolower($filename), 'hindi only') => 'hindi',
            default                                            => 'english',
        };

        $title = preg_replace('/\.pdf$/i', '', $filename);
        $year  = $this->parseYear($filename);

        $vaultDir  = implode('/', ['document_vault', $department->level, $department->slug, 'rules', $ruleSet->slug]);
        $slug      = Document::uniqueSlugForRuleSet($title, $ruleSet->id);
        $timestamp = now()->format('YmdHis');
        $storedAs  = "{$vaultDir}/{$slug}_{$timestamp}.pdf";

        Storage::disk('public')->put($storedAs, file_get_contents($file));

        try {
            $document = DB::transaction(function () use (
                $storedAs, $vaultDir, $slug, $filename, $title, $documentType, $parentId,
                $language, $year, $ruleSet, $department, $user
            ) {
                $document = Document::create([
                    'department_id'     => $department->id,
                    'rule_set_id'       => $ruleSet->id,
                    'parent_id'         => $parentId,
                    'user_id'           => $user->id,
                    'title'             => $title,
                    'slug'              => $slug,
                    'document_type'     => $documentType,
                    'language'          => $language,
                    'original_filename' => $filename,
                    'original_pdf_path' => $storedAs,
                    'vault_path'        => $vaultDir,
                    'status'            => 'uploaded',
                    'visibility'        => 'public',
                    'metadata'          => array_filter([
                        'amendment_number' => $this->parseAmendmentNumber($filename),
                        'effective_year'   => $year ?: null,
                    ]),
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => $user->id,
                    'from_status' => null,
                    'to_status'   => 'uploaded',
                    'note'        => 'Seeded from local excise rule-book batch import (rules:seed).',
                ]);

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($storedAs);
            throw $e;
        }

        $this->info("Imported: {$filename} → {$ruleSet->name} ({$documentType})");

        return $document;
    }
}
