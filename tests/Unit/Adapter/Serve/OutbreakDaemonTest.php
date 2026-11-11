<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\OutbreakDaemon;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('asks the daemon beside its files where the path fits a socket\'s address, and in the user\'s short directory otherwise', function (): void {
    $long = sprintf('/srv/%s', str_repeat('a', 120));

    expect(OutbreakDaemon::of('/srv/app', 501, Seconds::of(1.0))->proofs('k'))
        ->toEqual(NotWritten::because('No outbreak serve answers on /srv/app/.mutation-gate/outbreak/state.sock.'))
        ->and(OutbreakDaemon::of($long, 501, Seconds::of(1.0))->proofs('k'))
        ->toEqual(NotWritten::because(sprintf(
            'No outbreak serve answers on /tmp/mutation-gate-501/%s.sock.',
            substr(hash('sha256', $long), 0, 16),
        )));
});
