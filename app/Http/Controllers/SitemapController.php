<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;
use App\Models\RuleSet;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Public content only — this is what a search engine is allowed to see unauthenticated
     * anyway, same visibility rule the show routes themselves enforce. Cached for an hour since
     * crawlers re-fetch a sitemap often and the document count only grows a handful of times
     * a day at most (see documents:convert-all's batch cadence).
     */
    public function index(): Response
    {
        $urls = Cache::remember('sitemap.urls', 3600, function () {
            $urls = [];

            $urls[] = ['loc' => route('home'), 'priority' => '1.0'];
            $urls[] = ['loc' => route('search.index'), 'priority' => '0.5'];

            foreach (Department::all() as $department) {
                $urls[] = ['loc' => route('departments.show', [$department->levelAlias(), $department]), 'priority' => '0.8'];
            }

            foreach (RuleSet::where('kind', 'rules')->with('department')->get() as $ruleSet) {
                $urls[] = ['loc' => route('departments.rules.show', [$ruleSet->department->levelAlias(), $ruleSet->department, $ruleSet]), 'priority' => '0.7'];
            }

            Document::publiclyVisible()
                ->whereIn('status', ['review', 'verified'])
                ->with(['department', 'section', 'ruleSet', 'division', 'folder'])
                ->chunk(500, function ($documents) use (&$urls) {
                    foreach ($documents as $document) {
                        $loc = $this->documentUrl($document);
                        if ($loc) {
                            $urls[] = ['loc' => $loc, 'priority' => '0.6'];
                        }
                    }
                });

            return $urls;
        });

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }

    private function documentUrl(Document $document): ?string
    {
        $level = $document->department->levelAlias();

        if ($document->ruleSet) {
            return route("documents.{$document->ruleSet->kind}.show", [$level, $document->department, $document->ruleSet, $document]);
        }
        if ($document->folder && $document->division) {
            return route('documents.divisions.folders.show', [$level, $document->department, $document->section, $document->division, $document->folder, $document]);
        }
        if ($document->folder) {
            return route('documents.folders.show', [$level, $document->department, $document->section, $document->folder, $document]);
        }
        if ($document->division) {
            return route('documents.divisions.show', [$level, $document->department, $document->section, $document->division, $document]);
        }
        if ($document->section) {
            return route('documents.show', [$level, $document->department, $document->section, $document]);
        }

        return null;
    }
}
