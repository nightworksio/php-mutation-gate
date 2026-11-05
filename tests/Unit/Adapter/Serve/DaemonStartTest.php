<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\DaemonStart;
use NightWorksIO\MutationGate\Adapter\Serve\Listener;
use NightWorksIO\MutationGate\Adapter\Serve\ServeSocket;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Appeared;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    ServedProjects::left();
    Scratch::sweep();
});

/** A script that stands in for the gate's, which PHP runs with `serve --detached`: its path. */
$standIn = static function (string $project, string $body): string {
    Scratch::write($project, 'gate.php', sprintf("<?php\nfile_put_contents('argv.part', implode(' ', array_slice(\$argv, 1)));\nrename('argv.part', 'argv');\n%s\n", $body));

    return sprintf('%s/gate.php', $project);
};

it('starts a daemon by the gate\'s script, as `serve --detached` in the project, and waits until it listens', function () use ($standIn): void {
    $project = ServedProjects::entered();
    $script = $standIn($project, "\$s = stream_socket_server('unix://.mutation-gate/serve.sock');\nsleep(2);");
    $socket = ServeSocket::at('.mutation-gate/serve.sock', posix_geteuid());

    expect(new DaemonStart($socket, Seconds::of(5.0))->started($script, $project))->toBeTrue()
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

    expect(new DaemonStart(ServeSocket::at('.mutation-gate/serve.sock', posix_geteuid()), Seconds::of(5.0))->started($script, $project))->toBeTrue()
        ->and(new DaemonStart(ServeSocket::at('.mutation-gate/serve.sock', posix_geteuid() + 1), Seconds::of(5.0))->started($script, $project))->toBeFalse()
        ->and(file_exists('argv'))->toBeFalse();
});


it('starts the daemon with its input, output and errors on the null device, so it prints nothing where it was started', function () use ($standIn): void {
    $project = ServedProjects::entered();
    $script = $standIn($project, "fwrite(STDOUT, 'out');\nfwrite(STDERR, 'err');\nfile_put_contents('read', (string) fgets(STDIN));\n\$s = stream_socket_server('unix://.mutation-gate/serve.sock');\nsleep(2);");
    $starter = sprintf('%s/start.php', $project);
    file_put_contents($starter, sprintf(
        "<?php\nrequire %s;\nexit(new %s(%s::at('.mutation-gate/serve.sock', posix_geteuid()), %s::of(5.0))->started(%s, %s) ? 0 : 1);\n",
        var_export(Tree::at('vendor/autoload.php'), return: true),
        DaemonStart::class,
        ServeSocket::class,
        Seconds::class,
        var_export($script, return: true),
        var_export($project, return: true),
    ));
    $starting = new Process([PHP_BINARY, $starter], $project, input: "secret\n");
    $starting->run();

    expect($starting->getExitCode())->toBe(0)
        ->and($starting->getOutput())->toBe('')
        ->and($starting->getErrorOutput())->toBe('')
        ->and(Appeared::file('read') ? file_get_contents('read') : 'unread')->toBe('');
});
