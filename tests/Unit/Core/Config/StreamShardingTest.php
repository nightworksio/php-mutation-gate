<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\StreamSharding;

it('cuts the test files under tests, holding each held unit whole in one shard only by default', function (StreamSharding $sharding, bool $cuts, bool $whole): void {
    expect($sharding->cutsTests())->toBe($cuts)
        ->and($sharding->holdsWhole())->toBe($whole);
})->with([
    'tests' => [StreamSharding::Tests, true, true],
    'tests in every part' => [StreamSharding::TestsEveryPart, true, false],
    'source' => [StreamSharding::Source, false, false],
]);
