<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Psalm\LanguageServer;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Psalm/LanguageServer.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * How long a start or a check of the stand-in server may take, long beside the moment either takes, so one a
 * mutant breaks fails its test rather than runs out of the mutant's time.
 */
function languageServerLimit(): Seconds
{
    return Seconds::of(10.0);
}

/** The stand-in server, started in a project made for it. */
function languageServerIn(string $project): LanguageServer
{
    $server = LanguageServer::started([PHP_BINARY, 'vendor/bin/psalm-language-server'], $project, [], languageServerLimit());

    return $server instanceof LanguageServer ? $server : throw new LogicException($server->why());
}

/**
 * The version each file's diagnostics were published at, by its URI.
 *
 * @param  array<string, Node>|CannotJudge $published
 * @return array<string, int>
 */
function languageServerVersions(array|CannotJudge $published): array
{
    $versions = [];

    foreach (is_array($published) ? $published : [] as $uri => $params) {
        $versions[$uri] = Lenient::integer($params->field('version'));
    }

    return $versions;
}

it('cannot start in a directory that is not there', function (): void {
    $started = LanguageServer::started([PHP_BINARY, '-v'], sprintf('%s/gone', Scratch::directory()), []);

    expect($started instanceof CannotJudge ? $started->why() : '')->toStartWith('Psalm\'s language server did not start (');
})->group(...$holds);

it('says what the server published of each file at the version sent, and nothing of a file it does not analyse', function (): void {
    $project = FakeAnalyser::psalm('Psalm 6.19.1@abc');
    $server = languageServerIn($project);
    $money = sprintf('%s/src/Money.php', $project);
    $first = $server->analysed([$money => "<?php\n// error: A first\n"], languageServerLimit());
    $server->restore([$money => "<?php\n"]);
    $second = $server->analysed([$money => "<?php\n// info: B second\n", sprintf('%s/tests/Money Test+%%.php', $project) => "<?php\n"], languageServerLimit());
    $uri = sprintf('file://%s/src/Money.php', implode('/', array_map(rawurlencode(...), explode('/', $project))));
    $messages = is_array($second) ? Lenient::items($second[$uri]->field('diagnostics')) : [];

    expect(languageServerVersions($first))->toBe([$uri => 1])
        ->and(languageServerVersions($second))->toBe([$uri => 3])
        ->and(array_map(static fn(Node $diagnostic): string => Lenient::text($diagnostic->field('message')), $messages))
        ->toBe(['[B] second']);
})->group(...$holds);

it('reads nothing the server published of a file\'s text older than the one sent', function (): void {
    $project = FakeAnalyser::psalm('Psalm 6.19.1@abc');
    Scratch::write($project, 'vendor/bin/server.mode', 'stale');
    $server = languageServerIn($project);
    $money = sprintf('%s/src/Money.php', $project);
    $server->analysed([$money => "<?php\n"], languageServerLimit());

    expect($server->analysed([$money => "<?php\n// error: A late\n"], languageServerLimit()))->toBe([]);
})->group(...$holds);

it('reads a server that frames each message with its length alone, from its first message on', function (): void {
    $project = FakeAnalyser::psalm('Psalm 6.19.1@abc');
    Scratch::write($project, 'vendor/bin/server.mode', 'bare');
    $server = languageServerIn($project);
    $money = sprintf('%s/src/Money.php', $project);
    $uri = sprintf('file://%s/src/Money.php', implode('/', array_map(rawurlencode(...), explode('/', $project))));

    expect(languageServerVersions($server->analysed([$money => "<?php\n// error: A first\n"], languageServerLimit())))->toBe([$uri => 1]);
})->group(...$holds);

it('initialises the server with the gate\'s process, the project\'s root and what the gate reads', function (): void {
    $project = FakeAnalyser::psalm('Psalm 6.19.1@abc');
    languageServerIn($project)->analysed([sprintf('%s/src/Money.php', $project) => "<?php\n"], languageServerLimit());
    $initialized = json_decode((string) file_get_contents(sprintf('%s/vendor/bin/server-initialize.json', $project)), associative: true);

    expect($initialized)->toBe([
        'processId' => getmypid(),
        'rootUri' => sprintf('file://%s', implode('/', array_map(rawurlencode(...), explode('/', $project)))),
        'capabilities' => ['textDocument' => ['publishDiagnostics' => ['versionSupport' => true, 'dataSupport' => true]]],
    ]);
})->group(...$holds);

it('lets the server end on its own once its input ends, before it stops it', function (): void {
    $project = FakeAnalyser::psalm('Psalm 6.19.1@abc');
    Scratch::write($project, 'vendor/bin/server.mode', 'lingers');
    $server = languageServerIn($project);
    unset($server);

    expect((string) file_get_contents(sprintf('%s/vendor/bin/server-got.txt', $project)))->toEndWith("closed\n");
})->group(...$holds);

it('waits as long as a check with no limit takes, after a start within one', function (): void {
    $project = FakeAnalyser::psalm('Psalm 6.19.1@abc');
    Scratch::write($project, 'vendor/bin/server.mode', 'slow');
    $server = LanguageServer::started([PHP_BINARY, 'vendor/bin/psalm-language-server'], $project, [], Seconds::of(1.9));
    $money = sprintf('%s/src/Money.php', $project);
    $uri = sprintf('file://%s/src/Money.php', implode('/', array_map(rawurlencode(...), explode('/', $project))));

    expect(languageServerVersions($server instanceof LanguageServer ? $server->analysed([$money => "<?php\n"]) : $server))
        ->toBe([$uri => 1]);
})->group(...$holds);
