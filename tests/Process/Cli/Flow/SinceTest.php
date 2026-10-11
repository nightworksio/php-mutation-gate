<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Cli\Flow\Since;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Verdict\ChangeReach;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Cli/Flow/NameGraph.php',
    'holds:src/Cli/Flow/Since.php',
    'holds:src/Cli/Flow/Suite.php',
    'holds:src/Core/Php/Words.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

/** A repository whose one commit has src/Money.php use the trait src/Equals.php declares, and src/Tax.php alone. */
function sinceRepository(): Repository
{
    return Repository::empty()
        ->write('src/Money.php', "<?php\nfinal class Money\n{\n    use Equals;\n}\n")
        ->write('src/Equals.php', "<?php\ntrait Equals\n{\n}\n")
        ->write('src/Tax.php', "<?php\nfinal class Tax\n{\n}\n")
        ->write('tests/MoneyTest.php', "<?php\nit('adds', fn () => expect(1)->toBe(1));\n")
        ->commit('The code.');
}

it('follows what changed since a commit, read from git and the files on disk, by name', function (Closure $change, bool $reaches): void {
    $repository = sinceRepository();
    $commit = Revision::ref(trim($repository->git('rev-parse', 'HEAD')));
    $change($repository);
    $since = new Since(Flows::adapters($repository->root, [], Git::at($repository->root)), Flows::settings())->of($commit)->at($commit);

    expect($since)->toBeInstanceOf(ChangeReach::class)
        ->and($since instanceof ChangeReach ? $since->reaches(Path::of('src/Money.php'), Paths::none()) : $since)->toBe($reaches);
})->with([
    'the trait the unit uses' => [
        static fn(Repository $repository): Repository => $repository->write('src/Equals.php', "<?php\ntrait Equals\n{\n    public function same(): bool\n    {\n        return true;\n    }\n}\n"),
        true,
    ],
    'a new file nothing names' => [static fn(Repository $repository): Repository => $repository->write('src/Rate.php', "<?php\nfinal class Rate\n{\n}\n"), false],
    'a file the unit names, deleted' => [static fn(Repository $repository): bool => unlink(sprintf('%s/src/Equals.php', $repository->root)), true],
])->group(...$holds);

it('follows what changed in the tests to the test files that name it, a fixture by its name and support by its class', function (string $changed, string $contents): void {
    $repository = sinceRepository()
        ->write('tests/Support/Builder.php', "<?php\nnamespace Tests\\Support;\n\nfinal class Builder\n{\n}\n")
        ->write('tests/fixtures/rates.json', '{}')
        ->write('tests/BuilderTest.php', "<?php\nuse Tests\\Support\\Builder;\n\nit('builds', fn () => expect(new Builder())->not->toBeNull());\n")
        ->write('tests/RatesTest.php', "<?php\nit('rates', fn () => expect(file_get_contents(__DIR__ . '/fixtures/rates.json'))->toBe('{}'));\n")
        ->commit('The tests.');
    $commit = Revision::ref(trim($repository->git('rev-parse', 'HEAD')));
    $repository->write($changed, $contents);
    $since = new Since(Flows::adapters($repository->root, [], Git::at($repository->root)), Flows::settings())->of($commit)->at($commit);
    $reaches = static fn(string $test): bool => $since instanceof ChangeReach && $since->reaches(Path::of('src/Tax.php'), Paths::of(Path::of($test)));

    expect($reaches($changed === 'tests/fixtures/rates.json' ? 'tests/RatesTest.php' : 'tests/BuilderTest.php'))->toBeTrue()
        ->and($reaches($changed === 'tests/fixtures/rates.json' ? 'tests/BuilderTest.php' : 'tests/RatesTest.php'))->toBeFalse()
        ->and($reaches('tests/MoneyTest.php'))->toBeFalse();
})->with([
    'a fixture' => ['tests/fixtures/rates.json', '{"eur": 1}'],
    'support' => ['tests/Support/Builder.php', "<?php\nnamespace Tests\\Support;\n\nfinal class Builder\n{\n    public int \$size = 1;\n}\n"],
])->group(...$holds);

it('follows a change to a file composer.json has loaded in every process to every kill, and one to another file that runs code by its name', function (string $changed, bool $everything): void {
    $repository = sinceRepository()
        ->write('composer.json', '{"autoload": {"files": ["src/helpers.php"]}}')
        ->write('src/helpers.php', "<?php\nfunction money(): int\n{\n    return 1;\n}\n")
        ->write('modules/billing/composer.json', '{"autoload-dev": {"files": ["functions.php"]}}')
        ->write('modules/billing/functions.php', "<?php\nfunction tax(): int\n{\n    return 1;\n}\n")
        ->write('src/rates.php', "<?php\nreturn ['eur' => 1];\n")
        ->write('src/Rate.php', "<?php\nfinal class Rate\n{\n    public function all(): array\n    {\n        return require __DIR__ . '/rates.php';\n    }\n}\n")
        ->commit('The helpers.');
    $commit = Revision::ref(trim($repository->git('rev-parse', 'HEAD')));
    $repository->write($changed, "<?php\nreturn ['eur' => 2];\n");
    $since = new Since(Flows::adapters($repository->root, [], Git::at($repository->root)), Flows::settings())->of($commit)->at($commit);

    expect($since instanceof ChangeReach ? count($since->everything()) > 0 : $since)->toBe($everything)
        ->and($since instanceof ChangeReach && $since->reaches(Path::of('src/Rate.php'), Paths::none()))->toBeTrue()
        ->and($since instanceof ChangeReach && $since->reaches(Path::of('src/Tax.php'), Paths::none()))->toBe($everything);
})->with([
    'the autoloaded file' => ['src/helpers.php', true],
    'a module\'s autoloaded file' => ['modules/billing/functions.php', true],
    'a file that runs code, which another requires' => ['src/rates.php', false],
])->group(...$holds);

it('cannot tell what changed where a package\'s composer.json cannot be read', function (): void {
    $repository = sinceRepository();
    $commit = Revision::ref(trim($repository->git('rev-parse', 'HEAD')));
    $repository->write('composer.json', '{');
    $since = new Since(Flows::adapters($repository->root, [], Git::at($repository->root)), Flows::settings())->of($commit)->at($commit);

    expect($since instanceof CannotTell ? $since->why() : $since)
        ->toBe('The files on disk cannot be read to follow what changed by name. composer.json is not a JSON object.');
})->group(...$holds);

it('cannot tell what changed since a commit the repository does not have, nor one a shallow clone left out', function (): void {
    $origin = sinceRepository();
    $first = trim($origin->git('rev-parse', 'HEAD'));
    $origin->write('src/Tax.php', "<?php\nfinal class Tax\n{\n    // changed\n}\n")->commit('A change.');
    $clone = Scratch::directory();
    $origin->git('clone', '--quiet', '--depth', '1', sprintf('file://%s', $origin->root), $clone);
    $missing = str_repeat('0', 40);
    $shallow = new Since(Flows::adapters($clone, [], Git::at($clone)), Flows::settings())->of(Revision::ref($first))->at(Revision::ref($first));
    $unknown = new Since(Flows::adapters($origin->root, [], Git::at($origin->root)), Flows::settings())->of(Revision::ref($missing))->at(Revision::ref($missing));

    expect($shallow instanceof CannotTell ? $shallow->why() : $shallow)->toStartWith(sprintf('%s is not a revision this repository has: the clone is shallow', $first))
        ->and($unknown)->toEqual(CannotTell::because(sprintf('%s is not a revision this repository has.', $missing)));
})->group(...$holds);

it('cannot tell what changed where the files on disk cannot be read', function (): void {
    $repository = sinceRepository();
    $commit = Revision::ref(trim($repository->git('rev-parse', 'HEAD')));
    $adapters = Flows::adapters($repository->root, [], Git::at($repository->root), new TreeSourceFake(CannotJudge::because('No tree is declared.')));

    expect(new Since($adapters, Flows::settings())->of($commit)->at($commit))
        ->toEqual(CannotTell::because('The files on disk cannot be read to follow what changed by name. No tree is declared.'));
})->group(...$holds);

it('cannot tell what changed where git cannot list the files', function (): void {
    $repository = sinceRepository();
    $commit = Revision::ref(trim($repository->git('rev-parse', 'HEAD')));
    rename(sprintf('%s/.git', $repository->root), sprintf('%s/.git-gone', $repository->root));
    $since = new Since(Flows::adapters($repository->root, [], Git::at($repository->root)), Flows::settings())->of($commit)->at($commit);

    expect($since instanceof CannotTell ? $since->why() : $since)
        ->toStartWith('The files on disk cannot be read to follow what changed by name.')
        ->toContain('git');
})->group(...$holds);

it('cannot tell what changed where a file on disk leads out of the project', function (string $file): void {
    $repository = sinceRepository();
    $commit = Revision::ref(trim($repository->git('rev-parse', 'HEAD')));
    symlink('/etc/hosts', sprintf('%s/%s', $repository->root, $file));
    $since = new Since(Flows::adapters($repository->root, [], Git::at($repository->root)), Flows::settings())->of($commit)->at($commit);

    expect($since instanceof CannotTell ? $since->why() : $since)
        ->toStartWith('The files on disk cannot be read to follow what changed by name.')
        ->toContain($file);
})->with([
    'a PHP file outside the tests' => ['src/Escape.php'],
    'a test file' => ['tests/EscapeTest.php'],
])->group(...$holds);

it('reads no file outside the tests that is not PHP, as nothing names a file by what it holds', function (): void {
    $repository = sinceRepository();
    $commit = Revision::ref(trim($repository->git('rev-parse', 'HEAD')));
    symlink('/etc/hosts', sprintf('%s/notes.md', $repository->root));

    expect(new Since(Flows::adapters($repository->root, [], Git::at($repository->root)), Flows::settings())->of($commit)->at($commit))
        ->toBeInstanceOf(ChangeReach::class);
})->group(...$holds);
