<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\DaemonStart;
use NightWorksIO\MutationGate\Adapter\Serve\Listener;
use NightWorksIO\MutationGate\Adapter\Serve\ServeSocket;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Appeared;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;

afterEach(function (): void {
    ServedProjects::left();
    Scratch::sweep();
});

/** A script that stands in for the gate's, which PHP runs with `serve --detached`: its path. */
$standIn = static function (string $project, string $body): string {
    Scratch::write($project, 'gate.php', sprintf("<?php\nfile_put_contents('argv', implode(' ', array_slice(\$argv, 1)));\n%s\n", $body));

    return sprintf('%s/gate.php', $project);
};

it('starts a daemon by the gate\'s script, as `serve --detached` in the project, and waits until it listens', function () use ($standIn): void {
    $project = ServedProjects::entered();
    $script = $standIn($project, "\$s = stream_socket_server('unix://.mutation-gate/serve.sock');\nsleep(2);");
    $socket = ServeSocket::at('.mutation-gate/serve.sock', posix_geteuid());

    expect(new DaemonStart($socket)->started($script, $project))->toBeTrue()
        ->and(file_get_contents('argv'))->toBe('serve --detached');
});

it('removes a socket file nobody answers on before it starts one', function () use ($standIn): void {
    $project = ServedProjects::entered();
    $listener = Listener::boundTo('.mutation-gate/serve.sock');

    if ($listener instanceof Listener) {
        $listener->closed();
    }

    $script = $standIn($project, '');
    $started = new DaemonStart(ServeSocket::at('.mutation-gate/serve.sock', posix_geteuid()), Seconds::of(0.3))->started($script, $project);

    expect($started)->toBeFalse()
        ->and(Appeared::file('argv'))->toBeTrue()
        ->and(file_exists('.mutation-gate/serve.sock'))->toBeFalse()
        ->and(file_get_contents('argv'))->toBe('serve --detached');
});

it('starts none where one already listens, or where the socket is not the user\'s own', function () use ($standIn): void {
    $project = ServedProjects::entered();
    $listener = Listener::boundTo('.mutation-gate/serve.sock');
    $script = $standIn($project, '');

    expect(new DaemonStart(ServeSocket::at('.mutation-gate/serve.sock', posix_geteuid()))->started($script, $project))->toBeTrue()
        ->and(new DaemonStart(ServeSocket::at('.mutation-gate/serve.sock', posix_geteuid() + 1))->started($script, $project))->toBeFalse()
        ->and(file_exists('argv'))->toBeFalse();
});
