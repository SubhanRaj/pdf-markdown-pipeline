<?php

use App\Http\Controllers\PolicyPeriodController;
use Illuminate\Support\Carbon;

test('a later-dated period supersedes the current one', function () {
    expect(PolicyPeriodController::isChronologicallyLater(
        Carbon::parse('2025-04-01'),
        Carbon::parse('2024-04-01'),
    ))->toBeTrue();
});

test('a backfilled older period does not supersede the current one', function () {
    // Regression: adding "2021-22" after "2024-25" is already current must not steal "current".
    expect(PolicyPeriodController::isChronologicallyLater(
        Carbon::parse('2021-04-01'),
        Carbon::parse('2024-04-01'),
    ))->toBeFalse();
});

test('missing dates never auto-supersede', function () {
    expect(PolicyPeriodController::isChronologicallyLater(null, Carbon::parse('2024-04-01')))->toBeFalse();
    expect(PolicyPeriodController::isChronologicallyLater(Carbon::parse('2024-04-01'), null))->toBeFalse();
    expect(PolicyPeriodController::isChronologicallyLater(null, null))->toBeFalse();
});
