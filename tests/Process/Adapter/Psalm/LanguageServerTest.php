<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Psalm\LanguageServer;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Psalm/LanguageServer.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

/** The stand-in server, started in a project made for it. */
function languageServerIn(string $project): LanguageServer
{
    $server = LanguageServer::started([PHP_BINARY, 'vendor/bin/psalm-language-server'], $project, []);

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
    $first = $server->analysed([$money => "<?php\n// error: A first\n"]);
    $server->restore([$money => "<?php\n"]);
    $second = $server->analysed([$money => "<?php\n// info: B second\n", sprintf('%s/tests/Money Test+%%.php', $project) => "<?php\n"]);
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
    $server->analysed([$money => "<?php\n"]);

    expect($server->analysed([$money => "<?php\n// error: A late\n"]))->toBe([]);
})->group(...$holds);

it('reads a server that frames each message with its length alone, from its first message on', function (): void {
    $project = FakeAnalyser::psalm('Psalm 6.19.1@abc');
    Scratch::write($project, 'vendor/bin/server.mode', 'bare');
    $server = languageServerIn($project);
    $money = sprintf('%s/src/Money.php', $project);
    $uri = sprintf('file://%s/src/Money.php', implode('/', array_map(rawurlencode(...), explode('/', $project))));

    expect(languageServerVersions($server->analysed([$money => "<?php\n// error: A first\n"])))->toBe([$uri => 1]);
})->group(...$holds);
