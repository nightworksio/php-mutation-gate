<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\PestWorkers;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'bootingTail' => [static fn(): Setting => PestWorkers::bootingTail(), 'pest.bootTail', true, 'bootingTail'],
    'tailUnbooted' => [static fn(): Setting => PestWorkers::tailUnbooted(), 'pest.bootTail', false, 'tailUnbooted'],
    'cachingInWorkers' => [static fn(): Setting => PestWorkers::cachingInWorkers(), 'pest.workerOpcache', true, 'cachingInWorkers'],
    'uncachedInWorkers' => [static fn(): Setting => PestWorkers::uncachedInWorkers(), 'pest.workerOpcache', false, 'uncachedInWorkers'],
    'compilingSchemata' => [static fn(): Setting => PestWorkers::compilingSchemata(), 'pest.schemata', true, 'compilingSchemata'],
    'withoutSchemata' => [static fn(): Setting => PestWorkers::withoutSchemata(), 'pest.schemata', false, 'withoutSchemata'],
    'bootingMutants' => [static fn(): Setting => PestWorkers::bootingMutants(), 'pest.bootMutants', true, 'bootingMutants'],
    'mutantsUnbooted' => [static fn(): Setting => PestWorkers::mutantsUnbooted(), 'pest.bootMutants', false, 'mutantsUnbooted'],
    'bootingChunks' => [static fn(): Setting => PestWorkers::bootingChunks(), 'pest.bootChunks', true, 'bootingChunks'],
    'chunksUnbooted' => [static fn(): Setting => PestWorkers::chunksUnbooted(), 'pest.bootChunks', false, 'chunksUnbooted'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('PestWorkers::%s(', $method));
})->with($calls);
