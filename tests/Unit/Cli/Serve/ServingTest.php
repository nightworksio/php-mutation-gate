<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Cli\Serve\Serving;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedCommands;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;

afterEach(function (): void {
    putenv('MUTATION_GATE_SERVED');
    ServedProjects::left();
    Scratch::sweep();
});

/** A script that stands in for the gate's in a project: it serves one request with exit code 7, then ends; its path. */
function standInGate(string $project): string
{
    Scratch::write($project, 'gate.php', <<<'PHP'
        <?php
        $server = stream_socket_server('unix://.mutation-gate/staged.sock');
        rename('.mutation-gate/staged.sock', '.mutation-gate/serve.sock');
        $connection = stream_socket_accept($server, 5);
        fgets($connection);
        fwrite($connection, "{\"stream\":\"exit\",\"text\":\"\",\"code\":7}\n");
        fclose($connection);
        PHP);

    return sprintf('%s/gate.php', $project);
}

/** The serving of a flows project with these settings, changed into, that starts the stand-in gate as its daemon. */
function standInServing(string $config): Serving
{
    $project = ServedProjects::enteredAt(FlowCommands::project($config));

    return ServedCommands::serving($project, standInGate($project));
}

/**
 * A daemon of the project, in a process of its own, that answers one request with these frames, then ends, its
 * socket gone: its process id, once it listens.
 */
function answeringWith(ServeFrame ...$frames): int
{
    $answering = ServedProjects::aside(static function () use ($frames): void {
        $server = stream_socket_server('unix://.mutation-gate/staged.sock');
        rename('.mutation-gate/staged.sock', '.mutation-gate/serve.sock');
        $connection = is_resource($server) ? stream_socket_accept($server, 5) : false;

        if (is_resource($connection)) {
            fgets($connection);
            unlink('.mutation-gate/serve.sock');

            foreach ($frames as $frame) {
                fwrite($connection, $frame->line());
            }

            fclose($connection);
        }
    });
    ServedProjects::until(static fn(): bool => file_exists('.mutation-gate/serve.sock'));

    return $answering;
}

/** The serving of a flows project with these settings, changed into. */
$servingOf = static fn(string $config = ''): Serving => ServedCommands::serving(ServedProjects::enteredAt(FlowCommands::project($config)));

it('hands no command a daemon does not serve, none from a daemon\'s own child, and none read from no command line', function () use ($servingOf): void {
    $serving = $servingOf();
    $output = new BufferedOutput();

    expect($serving->forwarded('run', new ArgvInput(['gate', 'run']), $output))->toBeInstanceOf(NotGiven::class)
        ->and($serving->forwarded('pre-commit', new ArrayInput([]), $output))->toBeInstanceOf(NotGiven::class);

    putenv('MUTATION_GATE_SERVED=1');

    expect($serving->forwarded('pre-commit', new ArgvInput(['gate', 'pre-commit']), $output))->toBeInstanceOf(NotGiven::class)
        ->and($output->fetch())->toBe('');
});

it('runs a hook on its own where no daemon runs, starting none', function (): void {
    $serving = standInServing('"local": {"serveStart": "watch"}');

    expect($serving->forwarded('pre-commit', new ArgvInput(['gate', 'pre-commit']), new BufferedOutput()))->toBeInstanceOf(NotGiven::class)
        ->and(file_exists('.mutation-gate/serve.sock'))->toBeFalse();
});

it('runs a command on its own where the socket is not the user\'s own, and says why, sending nothing', function () use ($servingOf): void {
    $serving = $servingOf();
    chmod('.mutation-gate', 0o755);
    $output = new BufferedOutput();
    $input = new ArgvInput(['gate', 'pre-push', '--stdin=refs']);

    expect($serving->forwarded('pre-push', $input, $output))->toBeInstanceOf(NotGiven::class)
        ->and($output->fetch())->toBe("Others may reach into .mutation-gate, so another user could put a socket there. So this command runs on its own.\n");
});

it('hands a hook to the daemon running, with its command line, the variables a daemon takes and no other, and ends with its exit code', function (): void {
    $project = ServedProjects::enteredAt(FlowCommands::project());
    $serving = ServedCommands::serving($project);
    ServedCommands::servedAside($project, 6);
    putenv('PRE_COMMIT_TO_REF=abc');
    putenv('SERVE_TEST_TOKEN=secret');

    $code = $serving->forwarded('pre-commit', new ArgvInput(['gate', 'pre-commit', '-v']), new BufferedOutput());
    putenv('PRE_COMMIT_TO_REF');
    putenv('SERVE_TEST_TOKEN');
    [$argv, $environment, $input] = ServedCommands::asked();

    expect($code)->toBe(6)
        ->and($argv)->toBe(['/nowhere', 'pre-commit', '-v'])
        ->and($environment)->toContain('PRE_COMMIT_TO_REF')
        ->and($environment)->not->toContain('SERVE_TEST_TOKEN')
        ->and($input)->toBe('');
});

it('hands the daemon git\'s lines a pre-push reads, once the socket and its peer are found to be the user\'s own', function (): void {
    $project = ServedProjects::enteredAt(FlowCommands::project());
    $serving = ServedCommands::serving($project);
    ServedCommands::servedAside($project, 0);
    $input = new ArgvInput(['gate', 'pre-push', 'origin']);
    Serving::reading($input, "refs/heads/main abc refs/heads/main def\n");

    $code = $serving->forwarded('pre-push', $input, new BufferedOutput());
    [, , $read] = ServedCommands::asked();

    expect($code)->toBe(0)
        ->and($read)->toBe("refs/heads/main abc refs/heads/main def\n");
});

it('starts a daemon for watch where none runs and local.serveStart lets it, and runs in it; and starts none where it does not', function (): void {
    $never = standInServing('"local": {"serveStart": "never"}');

    expect($never->forwarded('watch', new ArgvInput(['gate', 'watch']), new BufferedOutput()))->toBeInstanceOf(NotGiven::class)
        ->and(file_exists('.mutation-gate/serve.sock'))->toBeFalse();

    ServedProjects::left();
    $unread = standInServing('"local": {"serveStart": "sometimes"}');

    expect($unread->forwarded('watch', new ArgvInput(['gate', 'watch']), new BufferedOutput()))->toBeInstanceOf(NotGiven::class)
        ->and(file_exists('.mutation-gate/serve.sock'))->toBeFalse();

    ServedProjects::left();
    $serving = standInServing('');

    expect($serving->forwarded('watch', new ArgvInput(['gate', 'watch']), new BufferedOutput()))->toBe(7);
});

it('starts a daemon for watch where the one running refused it, and runs in it', function (): void {
    $serving = standInServing('');
    $refusing = answeringWith(ServeFrame::refused('The daemon is of another version.'));

    $answer = $serving->forwarded('watch', new ArgvInput(['gate', 'watch']), new BufferedOutput());
    ServedProjects::waitedFor($refusing);

    expect($answer)->toBe(7);
});

it('writes what the daemon printed where it printed it, and ends with its exit code', function (): void {
    $serving = standInServing('');
    $output = new ConsoleOutput();
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $stream = new ReflectionProperty(StreamOutput::class, 'stream');
    $stream->setValue($output, $out);
    $stream->setValue($output->getErrorOutput(), $err);
    $answering = answeringWith(
        ServeFrame::written(ServeStream::Output, "out\n"),
        ServeFrame::written(ServeStream::Error, "err\n"),
        ServeFrame::exited(5),
    );

    $answer = $serving->forwarded('pre-commit', new ArgvInput(['gate', 'pre-commit']), $output);
    ServedProjects::waitedFor($answering);

    expect([
        $answer,
        is_resource($out) ? stream_get_contents($out, offset: 0) : '',
        is_resource($err) ? stream_get_contents($err, offset: 0) : '',
    ])->toBe([5, "out\n", "err\n"]);
});

it('fails, saying why, where the daemon\'s answer breaks off', function (): void {
    $serving = standInServing('');
    $output = new BufferedOutput();
    $answering = answeringWith(ServeFrame::written(ServeStream::Output, "half\n"));

    $answer = $serving->forwarded('pre-commit', new ArgvInput(['gate', 'pre-commit']), $output);
    ServedProjects::waitedFor($answering);

    expect(is_int($answer) && $answer !== 0)->toBeTrue()
        ->and($output->fetch())->toStartWith("half\n")->toContain('The daemon ended the run before it said its exit code.');
});

it('runs a hook a daemon\'s own child runs on its own, though a daemon runs', function (): void {
    $serving = standInServing('');
    $answering = answeringWith(ServeFrame::exited(5));
    putenv('MUTATION_GATE_SERVED=1');

    try {
        $answer = $serving->forwarded('pre-commit', new ArgvInput(['gate', 'pre-commit']), new BufferedOutput());
    } finally {
        putenv('MUTATION_GATE_SERVED');
        Connection::to('.mutation-gate/serve.sock');
        ServedProjects::waitedFor($answering);
    }

    expect($answer)->toBeInstanceOf(NotGiven::class);
});

it('hands the command line what it read on standard input, where it read anything', function (): void {
    $input = new ArgvInput(['gate', 'pre-push']);
    Serving::reading($input, "a b c d\n");
    $stream = $input->getStream();
    $untouched = new ArgvInput(['gate', 'pre-push']);
    Serving::reading($untouched, '');

    expect(is_resource($stream) ? stream_get_contents($stream) : '')->toBe("a b c d\n")
        ->and($untouched->getStream())->toBeNull();
});

it('leaves a hook that runs on its own what it read on standard input', function () use ($servingOf): void {
    $serving = $servingOf();
    $input = new ArgvInput(['gate', 'pre-push', 'origin']);
    Serving::reading($input, "refs/heads/main abc refs/heads/main def\n");

    $code = $serving->forwarded('pre-push', $input, new BufferedOutput());
    $stream = $input->getStream();

    expect($code)->toBeInstanceOf(NotGiven::class)
        ->and(is_resource($stream) ? stream_get_contents($stream) : '')->toBe("refs/heads/main abc refs/heads/main def\n");
});

it('reads standard input only for a pre-push not handed its lines, and takes no option after --', function (): void {
    $project = ServedProjects::enteredAt(FlowCommands::project());
    $serving = ServedCommands::serving($project);
    ServedCommands::servedAside($project, 6);
    file_put_contents('other.json', '{"runner": "pest"}');
    $read = static function (string $command, ArgvInput $input) use ($serving): array {
        Serving::reading($input, "lines\n");

        return [$serving->forwarded($command, $input, new BufferedOutput()), ServedCommands::asked()[2]];
    };

    expect($read('pre-commit', new ArgvInput(['gate', 'pre-commit'])))->toBe([6, ''])
        ->and($read('pre-push', new ArgvInput(['gate', 'pre-push', '--', '--stdin=refs', '--config=other.json'])))
        ->toBe([6, "lines\n"]);
});
