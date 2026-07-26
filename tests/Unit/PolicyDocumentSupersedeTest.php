<?php

use App\Http\Controllers\PolicyDocumentController;
use Illuminate\Support\Carbon;

test('a later-dated policy document supersedes the current one', function () {
    expect(PolicyDocumentController::isChronologicallyLater(
        Carbon::parse('2025-04-01'),
        Carbon::parse('2024-04-01'),
    ))->toBeTrue();
});

test('a backfilled older policy document does not supersede the current one', function () {
    // Regression: adding "2021-22" after "2024-25" is already current must not steal "current".
    expect(PolicyDocumentController::isChronologicallyLater(
        Carbon::parse('2021-04-01'),
        Carbon::parse('2024-04-01'),
    ))->toBeFalse();
});

test('missing dates never auto-supersede', function () {
    expect(PolicyDocumentController::isChronologicallyLater(null, Carbon::parse('2024-04-01')))->toBeFalse();
    expect(PolicyDocumentController::isChronologicallyLater(Carbon::parse('2024-04-01'), null))->toBeFalse();
    expect(PolicyDocumentController::isChronologicallyLater(null, null))->toBeFalse();
});
