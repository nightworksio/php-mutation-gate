<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Plan\Unchanging;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

const UNCHANGING_COMMIT = '5eeca8f0a1b2c3d4e5f60718293a4b5c6d7e8f90';

const UNCHANGING_CHECK = 'mutation / verdict';

$layout = static fn(): Layout => Layout::standard(Paths::of(Path::of('phpunit.xml')))
    ->runBy(Glob::of('.github/workflows/gate.yml'))
    ->testedIn(SuiteDirectory::conventional())
    ->decidedAlsoBy(Glob::of('config/**'))
    ->readByTheConfig(ConfigReads::named(Path::of('mutation-gate/extra.json')));

$within = static fn(Layout $layout): Unchanging => Unchanging::within(
    $layout,
    Packages::of(Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())))),
    Path::of('mutation-gate.baseline.json'),
);

$unchanging = static fn(): Unchanging => $within($layout());

$passed = static fn(): Passed => Passed::of(Revision::ref(UNCHANGING_COMMIT), UNCHANGING_CHECK, 2)
    ->passedAt(Instant::at(new DateTimeImmutable('2026-10-01T12:00:00Z')));

$now = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-10T12:00:00Z');

$modified = static fn(string ...$paths): Changes => Changes::of(
    ...array_map(static fn(string $path): Change => Change::modified(Path::of($path), Lines::none()), $paths),
);

$ignoreUntil = static fn(string $day): IgnoredPattern => IgnoredPattern::of(
    Glob::of('src/**'),
    'boundary',
    'The bound is never reached',
    Day::of($day) instanceof Day ? Day::of($day) : Absent::setting(),
);

$why = static fn(Unchanged|Reason $judged): string => $judged instanceof Reason ? $judged->text() : 'it stands';

it('lets the verdict of the commit that passed stand where only files that do not matter to the gate changed', function () use ($unchanging, $passed, $modified, $now): void {
    $judged = $unchanging()->since($passed(), $modified('README.md', 'docs/guide.md', '.editorconfig'), UNCHANGING_CHECK, Listed::of(), $now());

    expect($judged)->toEqual(Unchanged::since($passed(), Instant::at(new DateTimeImmutable('2026-10-01T12:00:00Z'))));
});

it('lets it stand where nothing changed at all', function () use ($unchanging, $passed, $now): void {
    expect($unchanging()->since($passed(), Changes::none(), UNCHANGING_CHECK, Listed::of(), $now()))->toBeInstanceOf(Unchanged::class);
});

it('plans where no commit of the scope has passed, saying why', function () use ($unchanging, $now, $why): void {
    expect($why($unchanging()->since(CannotTell::because('No commit of this scope has passed yet.'), Changes::none(), UNCHANGING_CHECK, Listed::of(), $now())))
        ->toBe('No commit of this scope has passed yet.');
});

it('plans where the ledger does not say when the commit passed', function () use ($unchanging, $now, $why): void {
    $untimed = Passed::of(Revision::ref(UNCHANGING_COMMIT), UNCHANGING_CHECK, 0);

    expect($why($unchanging()->since($untimed, Changes::none(), UNCHANGING_CHECK, Listed::of(), $now())))
        ->toBe(sprintf('The ledger does not say when %s passed, so whether an ignore expired since cannot be told.', UNCHANGING_COMMIT));
});

it('plans where the commit passed under another check', function () use ($unchanging, $passed, $now, $why): void {
    expect($why($unchanging()->since($passed(), Changes::none(), 'nightly', Listed::of(), $now())))
        ->toBe(sprintf('%s passed under the check mutation / verdict, not nightly.', UNCHANGING_COMMIT));
});

it('plans where git cannot say what changed since the commit', function () use ($unchanging, $passed, $now, $why): void {
    expect($why($unchanging()->since($passed(), CannotTell::because('git is gone.'), UNCHANGING_CHECK, Listed::of(), $now())))
        ->toBe('git is gone.');
});

it('plans where a changed path matters to the gate, saying which and why', function (string $path, string $said) use ($unchanging, $passed, $modified, $now, $why): void {
    expect($why($unchanging()->since($passed(), $modified('README.md', $path), UNCHANGING_CHECK, Listed::of(), $now())))
        ->toBe(sprintf($said, $path));
})->with([
    'a PHP file of the code' => ['src/Money.php', '%s is PHP.'],
    'a PHP file outside every tree' => ['scripts/build.php', '%s is PHP.'],
    'a file a test reads' => ['tests/Fixtures/rates.json', '%s is in a directory of tests.'],
    'the gate\'s config' => ['mutation-gate.json', '%s decides how the gate runs.'],
    'a file the config reads beside itself' => ['mutation-gate/extra.json', '%s decides how the gate runs.'],
    'composer.json' => ['composer.json', '%s decides how the gate runs.'],
    'composer.lock' => ['composer.lock', '%s decides how the gate runs.'],
    'what Composer installed' => ['vendor/composer/installed.json', '%s decides how the gate runs.'],
    'the runner\'s definition' => ['phpunit.xml', '%s decides how the gate runs.'],
    'the CI definition that runs the gate' => ['.github/workflows/gate.yml', '%s decides how the gate runs.'],
    'a file reach.everything names' => ['config/app.yaml', '%s decides how the gate runs.'],
    'the baseline' => ['mutation-gate.baseline.json', '%s is the baseline.'],
]);

it('plans where a renamed file mattered to the gate as it was, though not as it is', function (string $from, string $said) use ($unchanging, $passed, $now, $why): void {
    $renamed = Changes::of(Change::renamed(Path::of($from), Path::of('docs/old.txt'), Lines::none()));

    expect($why($unchanging()->since($passed(), $renamed, UNCHANGING_CHECK, Listed::of(), $now())))->toBe(sprintf($said, $from));
})->with([
    'from PHP' => ['src/Money.php', '%s is PHP.'],
    'from the tests' => ['tests/Fixtures/rates.json', '%s is in a directory of tests.'],
    'from the config' => ['mutation-gate.json', '%s decides how the gate runs.'],
]);

it('plans where a deleted file mattered to the gate', function () use ($unchanging, $passed, $now, $why): void {
    $deleted = Changes::of(Change::deleted(Path::of('src/Gone.php')));

    expect($why($unchanging()->since($passed(), $deleted, UNCHANGING_CHECK, Listed::of(), $now())))->toBe('src/Gone.php is PHP.');
});

it('plans on any change where the config reads a file it cannot name, as every file then decides', function () use ($within, $passed, $modified, $now, $why): void {
    $unnamed = Layout::standard(Paths::none())->readByTheConfig(ConfigReads::unnamed('it reads a path it builds'));

    expect($why($within($unnamed)->since($passed(), $modified('README.md'), UNCHANGING_CHECK, Listed::of(), $now())))
        ->toBe('README.md decides how the gate runs.');
});

it('plans where an ignore that applied when the commit passed has expired since', function (string $day) use ($unchanging, $passed, $now, $ignoreUntil, $why): void {
    $ignores = Listed::of($ignoreUntil('2026-12-31'), $ignoreUntil($day));

    expect($why($unchanging()->since($passed(), Changes::none(), UNCHANGING_CHECK, $ignores, $now())))
        ->toBe(sprintf('The ignore of boundary in src/** has expired since %s passed.', UNCHANGING_COMMIT));
})->with([
    'one that expired in between' => ['2026-10-05'],
    'one whose last day was the day it passed' => ['2026-10-01'],
    'one whose last day was yesterday' => ['2026-10-09'],
]);

it('lets it stand where every ignore stands as it stood when the commit passed', function (string $day) use ($unchanging, $passed, $now, $ignoreUntil): void {
    expect($unchanging()->since($passed(), Changes::none(), UNCHANGING_CHECK, Listed::of($ignoreUntil($day)), $now()))
        ->toBeInstanceOf(Unchanged::class);
})->with([
    'one that had expired already' => ['2026-09-30'],
    'one that expires today' => ['2026-10-10'],
    'one that expires later' => ['2026-12-31'],
]);

it('lets it stand under an ignore that never expires', function () use ($unchanging, $passed, $now): void {
    $forever = IgnoredPattern::of(Glob::of('src/**'), 'boundary', 'The bound is never reached', Absent::setting());

    expect($unchanging()->since($passed(), Changes::none(), UNCHANGING_CHECK, Listed::of($forever), $now()))
        ->toBeInstanceOf(Unchanged::class);
});
