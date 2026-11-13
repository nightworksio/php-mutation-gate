<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\HeldUnits;
use NightWorksIO\MutationGate\Config\Reach;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'HeldUnits::coverageFromTheMap' => [static fn(): Setting => HeldUnits::coverageFromTheMap(), 'holds.coverage', 'map', 'HeldUnits::coverageFromTheMap'],
    'Reach::sinceJudgedOffBranch' => [static fn(): Setting => Reach::sinceJudgedOffBranch(), 'reach.defaultBranchSince', 'judged-off-branch', 'Reach::sinceJudgedOffBranch'],
    'Reach::sincePassed' => [static fn(): Setting => Reach::sincePassed(), 'reach.defaultBranchSince', 'passed', 'Reach::sincePassed'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);
