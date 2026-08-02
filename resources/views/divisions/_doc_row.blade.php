<x-document-row
    :doc="$doc"
    :url="route('documents.divisions.show', [$department->levelAlias(), $department, $section, $division, $doc])"
    :destroy-url="auth()->check() && auth()->user()->isAdmin() ? route('documents.divisions.destroy', [$department->levelAlias(), $department, $section, $division, $doc]) : null"
    :is-amendment="$isAmendment"
/>
