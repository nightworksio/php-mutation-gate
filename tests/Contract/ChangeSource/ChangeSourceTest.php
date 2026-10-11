<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$holds = [
    'holds:src/Adapter/Git',
    'holds:src/Adapter/GitHub',
    'holds:src/Core/Change/Tree.php',
    'holds:src/Core/File/Fingerprints.php',
    'holds:src/Core/File/Paths.php',
    'holds:src/Core/Time/Instant.php',
];

// What every change source answers over the fixture: since fixture-base, the
// working tree changed line 2 of src/Money.php and added src/Limit.php. One
// line per implementation.

$sources = [
    'the fake' => fn(): ChangeSource => ChangeSourceFake::ofTheFixture(),
    'git' => fn(): ChangeSource => Git::at(Repository::ofTheFixture()->root),
    'git, with GitHub proving nothing' => fn(): ChangeSource => PassedPullRequests::over(
        Git::at(Repository::ofTheFixture()->root),
        new MockHttpClient(static fn(): MockResponse => new MockResponse('{"message": "Not Found"}', ['http_code' => 404])),
        ['GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_SHA' => 'head'],
        'mutation / verdict',
    ),
];

afterEach(function (): void {
    Scratch::sweep();
});

it('says what changed since a base, on which lines', function (ChangeSource $source): void {
    $changes = $source->changesSince(Revision::ref('fixture-base'));
    $said = [];

    foreach ($changes instanceof Changes ? $changes : [] as $change) {
        $said[$change->path()->value()] = [$change->kind()->value, array_map(static fn(Line $line): int => $line->number(), iterator_to_array($change->lines(), preserve_keys: true))];
    }

    expect($said)->toBe([
        'src/Money.php' => ['modified', [2]],
        'src/Limit.php' => ['added', [1]],
    ]);
})->with($sources)->group(...$holds);

it('names every file whose content on disk is not what is staged', function (ChangeSource $source): void {
    $unstaged = $source->unstaged();
    $said = array_map(static fn(Path $path): string => $path->value(), $unstaged instanceof Paths ? [...$unstaged] : []);
    sort($said);

    expect($said)->toBe(['src/Limit.php', 'src/Money.php']);
})->with($sources)->group(...$holds);

it('says when each path last changed in a commit, a directory by its newest file, and nothing for one never committed', function (ChangeSource $source): void {
    $paths = Paths::of(Path::of('src/Money.php'), Path::of('src'), Path::of('src/Limit.php'));
    $changed = $source->lastChanged($paths);
    $said = [];

    foreach ($changed instanceof ByPath ? $changed : [] as $path => $at) {
        $said[$path->value()] = $at;
    }

    expect(array_keys($said))->toBe(['src/Money.php', 'src'])
        ->and($said['src'])->toEqual($said['src/Money.php'])
        ->and($source->lastChanged(Paths::none()))->toHaveCount(0);
})->with($sources)->group(...$holds);

it('cannot tell what changed since a revision it does not have', function (ChangeSource $source): void {
    expect($source->changesSince(Revision::ref('no-such-revision')))->toBeInstanceOf(CannotTell::class);
})->with($sources)->group(...$holds);

it('fingerprints every file by what it holds', function (ChangeSource $source): void {
    $fingerprints = $source->fingerprints();
    $money = $fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('src/Money.php')) : $fingerprints;
    $limit = $fingerprints instanceof Fingerprints ? $fingerprints->digestOf(Path::of('src/Limit.php')) : $fingerprints;

    expect($money)->toBeInstanceOf(Digest::class)
        ->and($limit)->toBeInstanceOf(Digest::class)
        ->and($money)->not->toEqual($limit);
})->with($sources)->group(...$holds);

it('reads a file as it was at a revision, and says when it was not there', function (ChangeSource $source): void {
    expect($source->fileAt(Path::of('src/Money.php'), Revision::ref('fixture-base')))->toEqual(Contents::of("<?php\nreturn 1;\n"))
        ->and($source->fileAt(Path::of('src/Money.php'), Revision::workingTree()))->toEqual(Contents::of("<?php\nreturn 2;\n"))
        ->and($source->fileAt(Path::of('src/Limit.php'), Revision::ref('fixture-base')))->toEqual(Missing::at(Path::of('src/Limit.php')));
})->with($sources)->group(...$holds);

it('reads files as they were at a revision together, each one there or missing', function (ChangeSource $source): void {
    $paths = Paths::of(Path::of('src/Money.php'), Path::of('src/Limit.php'));
    $held = static function (ByPath|CannotTell $files) use ($paths): array {
        $held = [];

        foreach ($paths as $path) {
            $held[] = $files instanceof ByPath ? $files->at($path, Contents::of('not asked')) : $files;
        }

        return $held;
    };

    expect($held($source->filesAt($paths, Revision::ref('fixture-base'))))->toEqual([Contents::of("<?php\nreturn 1;\n"), Missing::at(Path::of('src/Limit.php'))])
        ->and($held($source->filesAt($paths, Revision::workingTree())))->toEqual([Contents::of("<?php\nreturn 2;\n"), Contents::of("<?php\n")])
        ->and($source->filesAt($paths, Revision::ref('fixture-base')))->toHaveCount(2);
})->with($sources)->group(...$holds);

it('says what changed from a revision, on which lines', function (ChangeSource $source): void {
    $changes = $source->changesFrom(Revision::ref('fixture-base'));
    $said = [];

    foreach ($changes instanceof Changes ? $changes : [] as $change) {
        $said[$change->path()->value()] = [$change->kind()->value, array_map(static fn(Line $line): int => $line->number(), iterator_to_array($change->lines(), preserve_keys: true))];
    }

    expect($said)->toBe([
        'src/Money.php' => ['modified', [2]],
        'src/Limit.php' => ['added', [1]],
    ])
        ->and($source->changesFrom(Revision::ref('no-such-revision')))->toBeInstanceOf(CannotTell::class);
})->with($sources)->group(...$holds);

it('reads what changed from a revision off HEAD\'s history from that revision itself, not from where the two meet', function (ChangeSource $source): void {
    $from = $source->changesFrom(Revision::ref('side'));
    $since = $source->changesSince(Revision::ref('side'));
    $paths = static fn(Changes|CannotTell $changes): array => array_map(
        static fn(Change $change): string => sprintf('%s %s', $change->kind()->value, $change->path()->value()),
        $changes instanceof Changes ? [...$changes] : [],
    );

    expect($paths($from))->toBe(['modified src/Money.php', 'deleted src/Side.php', 'added src/Limit.php'])
        ->and($paths($since))->toBe(['modified src/Money.php', 'added src/Limit.php']);
})->with([
    'git' => fn(): ChangeSource => Git::at(changeSourceWithSide()->root),
    'git, with GitHub proving nothing' => fn(): ChangeSource => PassedPullRequests::over(
        Git::at(changeSourceWithSide()->root),
        new MockHttpClient(static fn(): MockResponse => new MockResponse('{"message": "Not Found"}', ['http_code' => 404])),
        ['GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_SHA' => 'head'],
        'mutation / verdict',
    ),
])->group(...$holds);

it('says a commit a shallow clone does not hold is past its history, and how to read it', function (): void {
    $origin = Repository::ofTheFixture()->commit('The change.');
    $first = trim($origin->git('rev-parse', 'fixture-base'));
    $clone = Scratch::directory();
    $origin->git('clone', '--quiet', '--depth', '1', sprintf('file://%s', $origin->root), $clone);
    $changes = Git::at($clone)->changesFrom(Revision::ref($first));

    expect(Git::at($clone)->isShallow())->toBeTrue()
        ->and(Git::at($origin->root)->isShallow())->toBeFalse()
        ->and(Git::at(Scratch::directory())->isShallow())->toBeInstanceOf(CannotTell::class)
        ->and($changes instanceof CannotTell ? $changes->why() : $changes)->toBe(sprintf(
            "%s is not a revision this repository has: the clone is shallow, so it holds only the newest commits.\n"
            . 'A clone of the whole history reads it, as `fetch-depth: 0` asks of `actions/checkout`.',
            $first,
        ));
})->group(...$holds);

/**
 * The fixture, with a branch `side` off its base that adds src/Side.php,
 * which HEAD's history does not hold.
 */
function changeSourceWithSide(): Repository
{
    $repository = Repository::empty()->write('src/Money.php', "<?php\nreturn 1;\n")->commit('The base.');
    $repository->git('checkout', '--quiet', '-b', 'side');
    $repository->write('src/Side.php', "<?php\n")->commit('A side.');
    $repository->git('checkout', '--quiet', 'main');

    return $repository->write('src/Money.php', "<?php\nreturn 2;\n")->write('src/Limit.php', "<?php\n");
}

it('says what a commit it has was made of, reads it as itself, and cannot read one it neither has nor can make again', function (ChangeSource $source): void {
    $judged = $source->judged(Revision::ref('fixture-base'));
    $gone = JudgedCommits::of(str_repeat('d', 40), str_repeat('a', 40), str_repeat('b', 40));

    expect($judged)->toBeInstanceOf(JudgedCommit::class)
        ->and($judged instanceof JudgedCommit ? $source->readable($judged) : $judged)
        ->toEqual($judged instanceof JudgedCommit ? $judged->commit() : $judged)
        ->and($source->readable($gone))->toBeInstanceOf(CannotTell::class);
})->with($sources)->group(...$holds);
