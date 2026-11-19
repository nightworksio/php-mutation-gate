<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Engine\Schema\SwitchCall;

it('switches a site by its id, and takes no values until it is told a call that does', function (): void {
    $switch = SwitchCall::of('on(%d)');
    $valuing = $switch->valuing('value(%1$d, %2$s, %3$s)');

    expect([$switch->on(4), $switch->values(), $valuing->values(), $valuing->on(4), $valuing->valued(4, '$a + 1', '$a - 1')])
        ->toBe(['on(4)', false, true, 'on(4)', 'value(4, $a + 1, $a - 1)']);
});
