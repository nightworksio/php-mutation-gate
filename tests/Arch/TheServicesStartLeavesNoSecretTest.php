<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A `docker` that logs each call, writes a login into the config it is given, fails a login or a run where the
 * directory says so, and reports each container it ran in the state the directory names.
 */
const SERVICES_DOCKER = <<<'SH'
    #!/bin/bash
    root="$(dirname "$0")/.."
    echo "$*" >> "${root}/calls"
    config="${HOME}/.docker"
    if [ "$1" = "--config" ]; then config="$2"; shift 2; fi
    case "$1" in
      login)
        [ ! -f "${root}/login-fails" ] || exit 1
        mkdir -p "${config}"; echo "{\"auths\": {\"r\": {\"auth\": \"$(cat)\"}}}" > "${config}/config.json" ;;
      run) [ ! -f "${root}/run-fails" ] || { echo "no such image" >&2; exit 1; }; touch "${root}/ran" ;;
      inspect) if [ -f "${root}/ran" ]; then cat "${root}/state" 2> /dev/null || echo "running none"; else echo "Error: No such object: ${*: -1}"; exit 1; fi ;;
    esac
    SH;

/** The services of one job: a private image with a password, a database password and a value too short to mask. */
const PRIVATE_SERVICE = '{"db": {"image": "ghcr.io/o/db:1", "credentials": {"username": "u", "password": "s3cret-pass"}, "env": {"POSTGRES_PASSWORD": "env-s3cret", "X": "1"}, "ports": ["5432:5432"], "options": "--health-cmd=\"pg_isready\" --health-retries=3"}, "off": {"image": ""}}';

/** A directory set up for services.sh: a stub `docker`, a home and a runner's temporary directory. */
function servicesRoot(): string
{
    $directory = Scratch::directory();

    foreach (['bin', 'home', 'temp'] as $made) {
        mkdir(sprintf('%s/%s', $directory, $made));
    }

    file_put_contents(sprintf('%s/bin/docker', $directory), SERVICES_DOCKER);
    chmod(sprintf('%s/bin/docker', $directory), 0755);

    return $directory;
}

/**
 * One step of services.sh in this directory: how it ended and what it printed. The start step runs with the
 * services in its environment, as the workflow gives them; every other with nothing but a PATH to bash and jq,
 * as the workflow's clean shell runs it.
 *
 * @return array{int, string}
 */
function servicesRun(string $directory, string $step, string $services = '{}', string $start = 'background'): array
{
    $environment = [
        ...array_map(static fn(): false => false, getenv()),
        'PATH' => sprintf('/usr/bin:/bin:%s', dirname(trim((string) shell_exec('command -v jq')))),
        'DOCKER' => sprintf('%s/bin/docker', $directory),
        'HOME' => sprintf('%s/home', $directory),
        'MUTATION_GATE_SERVICES_WAIT' => '2',
    ];
    $started = [
        ...$environment,
        'RUNNER_TEMP' => sprintf('%s/temp', $directory),
        'SERVICES' => $services,
        'SERVICES_START' => $start,
    ];
    $process = new Process(
        [
            '/bin/bash',
            '--noprofile',
            '--norc',
            Tree::at('resources/action/services.sh'),
            $step,
            sprintf('%s/temp/mutation-gate-services', $directory),
        ],
        env: $step === 'start' ? $started : $environment,
    );
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput()];
}

/**
 * Every call the stub `docker` was given, in order.
 *
 * @return list<string>
 */
function dockerCalls(string $directory): array
{
    $file = sprintf('%s/calls', $directory);
    $calls = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];

    return is_array($calls) ? $calls : [];
}

/**
 * The files under this directory, at any depth, that hold this text.
 *
 * @return list<string>
 */
function holding(string $directory, string $text): array
{
    $found = [];
    $names = scandir($directory);

    foreach (array_diff(is_array($names) ? $names : [], ['.', '..']) as $name) {
        $path = sprintf('%s/%s', $directory, $name);
        $found = match (true) {
            is_dir($path) => [...$found, ...holding($path, $text)],
            str_contains((string) file_get_contents($path), $text) => [...$found, $path],
            default => $found,
        };
    }

    return $found;
}

it('starts every service at once, a private image pulled under a config of its own, masks each secret, and leaves only the containers\' names once the step ends', function (): void {
    $directory = servicesRoot();

    [$code, $said] = servicesRun($directory, 'start', PRIVATE_SERVICE);
    $calls = dockerCalls($directory);
    $listed = sprintf('%s/temp/mutation-gate-services/services', $directory);

    expect($code)->toBe(0)
        ->and($said)->toContain("::add-mask::s3cret-pass\n")
        ->and($said)->toContain("::add-mask::env-s3cret\n")
        ->and($said)->not->toContain('::add-mask::1')
        ->and($calls[0])->toMatch('#^--config .*/temp/mutation-gate-docker-\w+ login ghcr\.io --username u --password-stdin$#')
        ->and($calls[1])->toMatch('#^--config .*/temp/mutation-gate-docker-\w+ pull ghcr\.io/o/db:1$#')
        ->and(array_slice($calls, 2))->toBe([
            'rm --force --volumes mutation-gate-db-local-0-job',
            'run --detach --name mutation-gate-db-local-0-job --env POSTGRES_PASSWORD=env-s3cret --env X=1 --publish 5432:5432 --health-cmd=pg_isready --health-retries=3 ghcr.io/o/db:1',
        ])
        ->and((string) file_get_contents($listed))->toBe("mutation-gate-db-local-0-job\n")
        ->and(is_writable($listed))->toBeFalse()
        ->and(glob(sprintf('%s/temp/mutation-gate-docker-*', $directory)))->toBe([])
        ->and([...holding(sprintf('%s/temp', $directory), 's3cret'), ...holding(sprintf('%s/home', $directory), 's3cret')])->toBe([]);
});

it('waits for each container the start left, with no services or secret in its environment, until each reports healthy, and removes them', function (): void {
    $directory = servicesRoot();
    servicesRun($directory, 'start', PRIVATE_SERVICE);
    $started = count(dockerCalls($directory));

    [$waited, $said] = servicesRun($directory, 'wait');
    [$stopped] = servicesRun($directory, 'stop');

    expect([$waited, $said, $stopped])->toBe([0, "mutation-gate-db-local-0-job: running none\n", 0])
        ->and(array_slice(dockerCalls($directory), $started))->toBe([
            'inspect --format {{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} mutation-gate-db-local-0-job',
            'rm --force --volumes mutation-gate-db-local-0-job',
        ]);
});

it('fails where a service is not healthy in time, is unhealthy, or no list of containers was left', function (): void {
    $late = servicesRoot();
    servicesRun($late, 'start', '{"db": {"image": "mysql:8.4"}}');
    file_put_contents(sprintf('%s/state', $late), 'running starting');
    $sick = servicesRoot();
    servicesRun($sick, 'start', '{"db": {"image": "mysql:8.4"}}');
    file_put_contents(sprintf('%s/state', $sick), 'running unhealthy');

    [$lateCode, $lateSaid] = servicesRun($late, 'wait');
    [$sickCode, $sickSaid] = servicesRun($sick, 'wait');
    [$noneCode, $noneSaid] = servicesRun(servicesRoot(), 'wait');

    expect([$lateCode, $sickCode, $noneCode])->toBe([1, 1, 1])
        ->and($lateSaid)->toContain('was not healthy within 2 s')
        ->and($sickSaid)->toContain('is running unhealthy')
        ->and($noneSaid)->toContain('No list of the service containers');
});

it('fails the start where a private image cannot be pulled or a container cannot start, its own config removed', function (): void {
    $refused = servicesRoot();
    touch(sprintf('%s/login-fails', $refused));
    $broken = servicesRoot();
    touch(sprintf('%s/run-fails', $broken));

    [$refusedCode, $refusedSaid] = servicesRun($refused, 'start', PRIVATE_SERVICE);
    [$brokenCode, $brokenSaid] = servicesRun($broken, 'start', '{"db": {"image": "mysql:8.4"}}');

    expect([$refusedCode, $brokenCode])->toBe([1, 1])
        ->and($refusedSaid)->toContain('could not be pulled with its credentials')
        ->and($brokenSaid)->toContain("did not start.\nno such image")
        ->and(glob(sprintf('%s/temp/mutation-gate-docker-*', $refused)))->toBe([])
        ->and(is_file(sprintf('%s/temp/mutation-gate-services/services', $broken)))->toBeFalse();
});

it('starts nothing under services-start: job, and refuses any other value', function (): void {
    $directory = servicesRoot();

    [$job] = servicesRun($directory, 'start', PRIVATE_SERVICE, 'job');
    [$other, $said] = servicesRun($directory, 'start', PRIVATE_SERVICE, 'later');

    expect([$job, $other, dockerCalls($directory)])->toBe([0, 2, []])
        ->and($said)->toContain('services-start is later: background or job');
});

it('starts and waits for no service at once where the job declares none, leaving an empty list', function (string $services): void {
    $directory = servicesRoot();
    $from = hrtime(as_number: true);

    $ran = [servicesRun($directory, 'start', $services), servicesRun($directory, 'wait'), servicesRun($directory, 'stop')];
    $seconds = (hrtime(as_number: true) - $from) / 1e9;

    expect($ran)->toBe([[0, ''], [0, ''], [0, '']])
        ->and((string) file_get_contents(sprintf('%s/temp/mutation-gate-services/services', $directory)))->toBe('')
        ->and(dockerCalls($directory))->toBe([])
        ->and($seconds)->toBeLessThan(1.0);
})->with([
    'no services' => ['{}'],
    'nothing set' => [''],
]);

it('waits for and removes only the containers the list names, an empty line in it skipped', function (): void {
    $directory = servicesRoot();
    servicesRun($directory, 'start', '{"db": {"image": "mysql:8.4"}}');
    $listed = sprintf('%s/temp/mutation-gate-services/services', $directory);
    chmod($listed, 0644);
    file_put_contents($listed, "\nmutation-gate-db-local-0-job\n\n");
    $started = count(dockerCalls($directory));

    $ran = [servicesRun($directory, 'wait'), servicesRun($directory, 'stop')];

    expect($ran)->toBe([[0, "mutation-gate-db-local-0-job: running none\n"], [0, '']])
        ->and(array_slice(dockerCalls($directory), $started))->toBe([
            'inspect --format {{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} mutation-gate-db-local-0-job',
            'rm --force --volumes mutation-gate-db-local-0-job',
        ]);
});

it('fails at once for a container that is gone, whatever docker says of it on its output', function (): void {
    $directory = servicesRoot();
    $listed = sprintf('%s/temp/mutation-gate-services/services', $directory);
    mkdir(dirname($listed), recursive: true);
    file_put_contents($listed, "mutation-gate-db-local-0-job\n");

    [$code, $said] = servicesRun($directory, 'wait');

    expect($code)->toBe(1)
        ->and($said)->toContain('::error::The service container mutation-gate-db-local-0-job is missing none.')
        ->and($said)->not->toContain('within 2 s');
});
