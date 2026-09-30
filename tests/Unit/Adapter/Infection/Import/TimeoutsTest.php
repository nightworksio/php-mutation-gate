<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\Timeouts;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Imports;

it('takes timeout as the seconds a mutant may run, and timeoutsAsEscaped as leaving timeouts unjudged', function (): void {
    $import = Timeouts::of(Node::decode('{"timeout": 20, "timeoutsAsEscaped": true}'));

    expect(Imports::written($import))->toBe('{"timeouts":{"mode":"unjudged","seconds":20}}')
        ->and(Imports::keys($import))->toBe([
            '  timeout: imported as timeouts.seconds: 20',
            '  timeoutsAsEscaped: imported as timeouts.mode: unjudged',
        ]);
});

it('drops a timeout that is no whole number of seconds, and timeoutsAsEscaped false', function (): void {
    $import = Timeouts::of(Node::decode('{"timeout": 0, "timeoutsAsEscaped": false}'));

    expect(Imports::written($import))->toBe('{}')
        ->and(Imports::keys($import))->toBe([
            '  timeout: dropped, because 0 is not a whole number of seconds',
            '  timeoutsAsEscaped: dropped, because the gate triages each timeout itself, where false counts it as killed',
        ])
        ->and(Imports::keys(Timeouts::of(Node::decode('{"timeout": "10"}'))))->toBe(['  timeout: dropped, because "10" is not a whole number of seconds'])
        ->and(Imports::keys(Timeouts::of(Node::decode('{"timeoutsAsEscaped": "yes"}'))))->toHaveCount(1)
        ->and(Imports::keys(Timeouts::of(Node::decode('{}'))))->toBe([]);
});
