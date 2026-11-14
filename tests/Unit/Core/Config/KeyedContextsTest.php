<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\KeyedContexts;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('names each setting that affects results the pr and main blocks resolve differently, and no other', function (array $contexts, array $keys): void {
    expect(KeyedContexts::of(Configs::valid(['runner' => 'pest', 'contexts' => $contexts]))->keys())->toBe($keys);
})->with([
    'no block' => [[], []],
    'a keyed setting for pull requests alone' => [['pr' => ['timeouts' => ['seconds' => 5]]], ['timeouts.seconds']],
    'one for the default branch alone' => [['main' => ['pest' => ['trials' => false]]], ['pest.trials']],
    'one alike in both' => [['pr' => ['timeouts' => ['seconds' => 5]], 'main' => ['timeouts' => ['seconds' => 5]]], []],
    'one that only reports' => [['pr' => ['suggest' => ['enabled' => false]]], []],
    'one for every CI run' => [['ci' => ['timeouts' => ['seconds' => 5]]], []],
]);
