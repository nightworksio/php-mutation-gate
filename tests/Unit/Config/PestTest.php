<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Pest;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'Pest::trustingNarrowedKills' => [static fn(): Setting => Pest::trustingNarrowedKills(), 'pest.narrowedKills', 'trust', 'Pest::trustingNarrowedKills'],
    'Pest::longFilterByFiles' => [static fn(): Setting => Pest::longFilterByFiles(), 'pest.longFilter', 'files', 'Pest::longFilterByFiles'],
    'Pest::longFilterBySet' => [static fn(): Setting => Pest::longFilterBySet(), 'pest.longFilter', 'set', 'Pest::longFilterBySet'],
    'Pest::longFilterUntilKiller' => [static fn(): Setting => Pest::longFilterUntilKiller(), 'pest.longFilter', 'until-killer', 'Pest::longFilterUntilKiller'],
    'Pest::preloadedUnjudged' => [static fn(): Setting => Pest::preloadedUnjudged(), 'pest.preloaded', 'unjudged', 'Pest::preloadedUnjudged'],
    'Pest::childProcessesServed' => [static fn(): Setting => Pest::childProcessesServed(), 'pest.childProcesses', 'serve', 'Pest::childProcessesServed'],
    'Pest::childProcessesOff' => [static fn(): Setting => Pest::childProcessesOff(), 'pest.childProcesses', 'off', 'Pest::childProcessesOff'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);
