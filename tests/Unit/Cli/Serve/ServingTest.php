<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Serve\Serving;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedCommands;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    putenv('MUTATION_GATE_SERVED');
    ServedProjects::left();
    Scratch::sweep();
});

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

it('runs a hook on its own where no daemon runs, starting none', function () use ($servingOf): void {
    $serving = $servingOf('"local": {"serveStart": "watch"}');

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

it('starts a daemon for watch where none runs and local.serveStart lets it, and runs in it; and starts none where it does not', function () use ($servingOf): void {
    $never = $servingOf('"local": {"serveStart": "never"}');

    expect($never->forwarded('watch', new ArgvInput(['gate', 'watch']), new BufferedOutput()))->toBeInstanceOf(NotGiven::class);

    ServedProjects::left();
    $project = ServedProjects::enteredAt(FlowCommands::project());
    Scratch::write($project, 'gate.php', <<<'PHP'
        <?php
        $server = stream_socket_server('unix://.mutation-gate/serve.sock');
        $connection = stream_socket_accept($server, 5);
        fgets($connection);
        fwrite($connection, "{\"stream\":\"exit\",\"text\":\"\",\"code\":7}\n");
        fclose($connection);
        PHP);
    $serving = ServedCommands::serving($project, sprintf('%s/gate.php', $project));

    expect($serving->forwarded('watch', new ArgvInput(['gate', 'watch']), new BufferedOutput()))->toBe(7);
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
