@php
    $isDivisionFolder = isset($division) && $division !== null;
    $showRoute = $isDivisionFolder ? 'documents.divisions.folders.show' : 'documents.folders.show';
    $destroyRoute = $isDivisionFolder ? 'documents.divisions.folders.destroy' : 'documents.folders.destroy';
    $showParams = $isDivisionFolder
        ? [$department->levelAlias(), $department, $section, $division, $folder, $doc]
        : [$department->levelAlias(), $department, $section, $folder, $doc];
@endphp

<x-document-row
    :doc="$doc"
    :url="route($showRoute, $showParams)"
    :destroy-url="auth()->check() && auth()->user()->isAdmin() ? route($destroyRoute, $showParams) : null"
    :is-amendment="$isAmendment"
/>
