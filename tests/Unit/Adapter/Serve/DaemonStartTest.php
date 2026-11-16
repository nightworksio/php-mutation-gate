<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Serve\DaemonStart;
use NightWorksIO\MutationGate\Adapter\Serve\Listener;
use NightWorksIO\MutationGate\Adapter\Serve\ServeSocket;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
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

it('keeps the gate\'s directory a run made, open to others under the umask, to the user alone, and starts the daemon there', function () use ($standIn): void {
    $project = ServedProjects::entered();
    rmdir('.mutation-gate');
    $umask = umask(0o022);
    Directory::at($project)->write(Path::of('.mutation-gate/ledger.json'), Contents::of('{}'));
    umask($umask);
    $made = fileperms('.mutation-gate') & 0o777;
    $script = $standIn($project, "\$s = stream_socket_server('unix://.mutation-gate/serve.sock');\nsleep(2);");

    expect($made)->toBe(0o755)
        ->and(new DaemonStart(ServedProjects::socket(), Seconds::of(5.0))->started($script, $project))->toBeTrue()
        ->and(fileperms('.mutation-gate') & 0o777)->toBe(0o700);
});

it('starts the daemon with its input, output and errors on the null device, so it prints nothing where it was started', function () use ($standIn): void {
    $project = ServedProjects::entered();
    $bound = $standIn($project, "stream_socket_server('unix://.mutation-gate/serve.sock');");
    $first = new DaemonStart(ServedProjects::socket(), Seconds::of(5.0))->started($bound, $project);
    $script = $standIn($project, "fwrite(STDOUT, 'out');\nfwrite(STDERR, 'err');\nfile_put_contents('read', (string) fgets(STDIN));\n\$s = stream_socket_server('unix://.mutation-gate/serve.sock');\nsleep(2);");
    file_put_contents('given', "secret\n");
    $starter = ServedProjects::aside(static function () use ($script, $project): void {
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $descriptors = [fopen('given', 'r'), fopen('printed', 'w'), fopen('warned', 'w')];
        $started = new DaemonStart(ServedProjects::socket(), Seconds::of(5.0))->started($script, $project);
        file_put_contents('started', var_export([$started, array_map(is_resource(...), $descriptors)], return: true));
    });
    ServedProjects::waitedFor($starter);

    expect($first)->toBeTrue()
        ->and(file_get_contents('started'))->toBe(var_export([true, [true, true, true]], return: true))
        ->and(file_get_contents('printed'))->toBe('')
        ->and(file_get_contents('warned'))->toBe('')
        ->and(Appeared::file('read') ? file_get_contents('read') : 'unread')->toBe('');
});
