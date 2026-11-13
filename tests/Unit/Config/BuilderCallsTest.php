<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Local;
use NightWorksIO\MutationGate\Config\Mutants;
use NightWorksIO\MutationGate\Config\Pest;
use NightWorksIO\MutationGate\Config\Reach;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = static fn(): array => [
    'Ci::trustingMergedDeltas' => [Ci::trustingMergedDeltas(), 'ci.trustMergedPullRequests', 'delta', 'Ci::trustingMergedDeltas'],
    'Ci::refusingMergedOwnScopeProofs' => [Ci::refusingMergedOwnScopeProofs(), 'ci.mergedOwnScopeProofs', 'refuse', 'Ci::refusingMergedOwnScopeProofs'],
    'Ci::trustingMergedOwnScopeProofs' => [Ci::trustingMergedOwnScopeProofs(), 'ci.mergedOwnScopeProofs', 'trust', 'Ci::trustingMergedOwnScopeProofs'],
    'Local::fullRounds' => [Local::fullRounds(), 'local.quick', false, 'Local::fullRounds'],
    'Local::cancellingByRound' => [Local::cancellingByRound(), 'local.cancelByUnit', false, 'Local::cancellingByRound'],
    'Mutants::judgingEach' => [Mutants::judgingEach(), 'mutants.duplicates', 'judge-each', 'Mutants::judgingEach'],
    'Pest::trustingNarrowedKills' => [Pest::trustingNarrowedKills(), 'pest.narrowedKills', 'trust', 'Pest::trustingNarrowedKills'],
    'Pest::longFilterBySet' => [Pest::longFilterBySet(), 'pest.longFilter', 'set', 'Pest::longFilterBySet'],
    'Pest::longFilterUntilKiller' => [Pest::longFilterUntilKiller(), 'pest.longFilter', 'until-killer', 'Pest::longFilterUntilKiller'],
    'Pest::preloadedUnjudged' => [Pest::preloadedUnjudged(), 'pest.preloaded', 'unjudged', 'Pest::preloadedUnjudged'],
    'Pest::bootingChunks' => [Pest::bootingChunks(), 'pest.bootChunks', true, 'Pest::bootingChunks'],
    'Reach::heldCoverageFromTheMap' => [Reach::heldCoverageFromTheMap(), 'holds.coverage', 'map', 'Reach::heldCoverageFromTheMap'],
    'Reach::sinceJudgedOffBranch' => [Reach::sinceJudgedOffBranch(), 'reach.defaultBranchSince', 'judged-off-branch', 'Reach::sinceJudgedOffBranch'],
    'Reach::sincePassed' => [Reach::sincePassed(), 'reach.defaultBranchSince', 'passed', 'Reach::sincePassed'],
    'Shards::pullingByClaims' => [Shards::pullingByClaims(), 'shards.pull', 'claim', 'Shards::pullingByClaims'],
    'Shards::pullingBySteals' => [Shards::pullingBySteals(), 'shards.pull', 'steal', 'Shards::pullingBySteals'],
    'Shards::owningTheirParts' => [Shards::owningTheirParts(), 'shards.pull', 'off', 'Shards::owningTheirParts'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Setting $call, string $path, string|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);
