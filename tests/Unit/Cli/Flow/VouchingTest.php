<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Vouching;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Fakes\MapEngineFake;

it('asks the engine whether it vouches for a text, and nothing where there is no engine', function (): void {
    $vouching = Vouching::by(new MapEngineFake(['{"format":1}']));

    expect($vouching instanceof Closure ? [$vouching('{"format":1}'), $vouching('{}')] : [])
        ->toBe([MapVouch::Vouched, MapVouch::Unvouched])
        ->and(Vouching::by(NotGiven::value()))->toEqual(NotGiven::value());
});
