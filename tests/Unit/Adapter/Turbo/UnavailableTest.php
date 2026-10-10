<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Turbo\Unavailable;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Request;

it('answers every request with why there is no helper', function (): void {
    expect(Unavailable::because(NotAccelerated::because('turned off'))->answer(Request::ofText('{}')))
        ->toEqual(NotAccelerated::because('turned off'));
});
