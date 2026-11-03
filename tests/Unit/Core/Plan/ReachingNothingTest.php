<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\ReachingNothing;
use NightWorksIO\MutationGate\Core\Plan\StandsBecause;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Moment;

const REACHING_NOTHING_COMMIT = '5eeca8f0a1b2c3d4e5f60718293a4b5c6d7e8f90';

const REACHING_NOTHING_CHECK = 'mutation / verdict';

const REACHING_NOTHING_PASSED_AT = '2026-11-02T12:43:14Z';

$reaching = static fn(): ReachingNothing => ReachingNothing::within(
    Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root()))),
    Path::of('mutation-gate.baseline.json'),
    Globs::of(Glob::of('mutation-gate.json'), Glob::of('mutation-gate/extra.json')),
    REACHING_NOTHING_CHECK,
);

$passed = static fn(string $check = REACHING_NOTHING_CHECK): Passed => Passed::of(Revision::ref(REACHING_NOTHING_COMMIT), $check, 0)
    ->passedAt(Instant::at(new DateTimeImmutable(REACHING_NOTHING_PASSED_AT)));

$proofsAt = static fn(string ...$at): Proofs => Proofs::of(...array_map(
    static fn(string $when): Proof => Proof::of(
        Digest::of(sprintf('key at %s', $when)),
        Path::of('src/Money.php'),
        Mutants::none(),
        Run::of('main', Moment::at($when), Digest::of(str_repeat('b', 64))),
    ),
    $at,
));

$modified = static fn(string ...$paths): Changes => Changes::of(
    ...array_map(static fn(string $path): Change => Change::modified(Path::of($path), Lines::none()), $paths),
);

$now = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-11-03T08:00:00Z');

$why = static fn(Unchanged|Reason $judged): string => $judged instanceof Reason ? $judged->text() : 'it stands';

it('lets the verdict of the commit the change is read since stand, where it passed and the change touches no tree and not the baseline', function () use ($reaching, $passed, $proofsAt, $modified, $now): void {
    $changes = $modified('.github/workflows/scorecard.yml', 'tests/Support/Tree.php', 'composer.lock');

    $judged = $reaching()->stands(
        $passed(),
        $proofsAt('2026-11-02T04:29:56Z', REACHING_NOTHING_PASSED_AT),
        Revision::ref(REACHING_NOTHING_COMMIT),
        $changes,
        $changes,
        Listed::of(),
        $now(),
    );

    expect($judged)->toEqual(Unchanged::standing(StandsBecause::NothingReached, $passed(), Instant::at(new DateTimeImmutable(REACHING_NOTHING_PASSED_AT))))
        ->and($judged instanceof Unchanged ? $judged->reason()->text() : $judged)->toBe(sprintf(
            'The change since %s, whose verdict passed at %s, reaches no unit, so its verdict stands.',
            REACHING_NOTHING_COMMIT,
            REACHING_NOTHING_PASSED_AT,
        ));
});

it('judges what it carries where the default branch\'s ledger holds no commit that passed, or none it says the instant of', function (Passed|CannotTell $passed, string $said) use ($reaching, $proofsAt, $modified, $now, $why): void {
    expect($why($reaching()->stands($passed, $proofsAt(), Revision::ref(REACHING_NOTHING_COMMIT), $modified('README.md'), $modified('README.md'), Listed::of(), $now())))
        ->toBe(sprintf($said, REACHING_NOTHING_COMMIT));
})->with([
    'none passed' => [fn(): CannotTell => CannotTell::because('No commit of this scope has passed yet.'), 'No commit of this scope has passed yet.'],
    'no instant' => [fn(): Passed => Passed::of(Revision::ref(REACHING_NOTHING_COMMIT), REACHING_NOTHING_CHECK, 0), 'The default branch\'s ledger does not say when %s passed.'],
]);

it('judges what it carries where the commit passed under another check, the change is read since another commit, or a later run left results', function (string $check, string $since, string $later, string $said) use ($reaching, $passed, $proofsAt, $modified, $now, $why): void {
    expect($why($reaching()->stands($passed($check), $proofsAt($later), Revision::ref($since), $modified('README.md'), $modified('README.md'), Listed::of(), $now())))
        ->toBe(sprintf($said, REACHING_NOTHING_COMMIT, REACHING_NOTHING_CHECK));
})->with([
    'another check' => ['gate', REACHING_NOTHING_COMMIT, REACHING_NOTHING_PASSED_AT, '%1$s passed under the check gate, not %2$s.'],
    'another commit' => [REACHING_NOTHING_CHECK, '0f44b56a', REACHING_NOTHING_PASSED_AT, 'The change is read since 0f44b56a, and the commit whose verdict passed is %1$s.'],
    'a later run' => [REACHING_NOTHING_CHECK, REACHING_NOTHING_COMMIT, '2026-11-02T12:43:15Z', 'A run after %1$s passed left results in the default branch\'s ledger.'],
]);

it('judges what it carries where git cannot tell either change, or the commit that passed is not where the change is read from', function (Changes|CannotTell $fromPassed, Changes|CannotTell $sinceBase, string $said) use ($reaching, $passed, $proofsAt, $now, $why): void {
    expect($why($reaching()->stands($passed(), $proofsAt(), Revision::ref(REACHING_NOTHING_COMMIT), $fromPassed, $sinceBase, Listed::of(), $now())))
        ->toBe(sprintf($said, REACHING_NOTHING_COMMIT));
})->with([
    'from the commit' => [fn(): CannotTell => CannotTell::because('git cannot read it.'), fn(): Changes => Changes::none(), 'git cannot read it.'],
    'since the base' => [fn(): Changes => Changes::none(), fn(): CannotTell => CannotTell::because('git cannot diff it.'), 'git cannot diff it.'],
    'not an ancestor' => [
        fn(): Changes => Changes::of(Change::modified(Path::of('README.md'), Lines::none()), Change::modified(Path::of('docs/a.md'), Lines::none())),
        fn(): Changes => Changes::of(Change::modified(Path::of('README.md'), Lines::none())),
        'What changed from %s itself is not what changed since its merge base with HEAD.',
    ],
]);

it('judges what it carries where the change touches a tree, as a path is or as it was, or the baseline', function (Change $change, string $said) use ($reaching, $passed, $proofsAt, $now, $why): void {
    $changes = Changes::of($change);

    expect($why($reaching()->stands($passed(), $proofsAt(), Revision::ref(REACHING_NOTHING_COMMIT), $changes, $changes, Listed::of(), $now())))
        ->toBe($said);
})->with([
    'a deleted unit' => [fn(): Change => Change::deleted(Path::of('src/Money.php')), 'src/Money.php is in the tree src.'],
    'a unit renamed away' => [fn(): Change => Change::renamed(Path::of('src/Money.php'), Path::of('lib/Money.php'), Lines::none()), 'src/Money.php is in the tree src.'],
    'a file renamed in' => [fn(): Change => Change::renamed(Path::of('lib/Money.php'), Path::of('src/Money.php'), Lines::none()), 'src/Money.php is in the tree src.'],
    'the baseline' => [fn(): Change => Change::modified(Path::of('mutation-gate.baseline.json'), Lines::none()), 'mutation-gate.baseline.json is the baseline.'],
    'the config' => [fn(): Change => Change::modified(Path::of('mutation-gate.json'), Lines::none()), 'mutation-gate.json decides how the gate runs.'],
    'a file the config reads' => [fn(): Change => Change::deleted(Path::of('mutation-gate/extra.json')), 'mutation-gate/extra.json decides how the gate runs.'],
]);

it('judges what it carries where an ignore that applied when the commit passed has expired since, and stands under one that lasts', function (string $until, string $said) use ($reaching, $passed, $proofsAt, $modified, $now, $why): void {
    $day = Day::of($until);
    $ignores = Listed::of(IgnoredPattern::of(Glob::of('src/**'), 'boundary', 'The bound is never reached', $day instanceof Day ? $day : Absent::setting()));

    expect($why($reaching()->stands($passed(), $proofsAt(), Revision::ref(REACHING_NOTHING_COMMIT), $modified('README.md'), $modified('README.md'), $ignores, $now())))
        ->toBe(sprintf($said, REACHING_NOTHING_COMMIT));
})->with([
    'expired since' => ['2026-11-02', 'The ignore of boundary in src/** has expired since %s passed.'],
    'lasting' => ['2026-12-31', 'it stands'],
]);

it('reads the two changes as one where they touch the same paths, in whatever order and however git names the moves', function () use ($reaching, $passed, $proofsAt, $now): void {
    $fromPassed = Changes::of(Change::renamed(Path::of('docs/a.md'), Path::of('docs/b.md'), Lines::none()), Change::modified(Path::of('README.md'), Lines::none()));
    $sinceBase = Changes::of(
        Change::modified(Path::of('README.md'), Lines::none()),
        Change::added(Path::of('docs/b.md'), Lines::none()),
        Change::deleted(Path::of('docs/a.md')),
    );

    expect($reaching()->stands($passed(), $proofsAt(), Revision::ref(REACHING_NOTHING_COMMIT), $fromPassed, $sinceBase, Listed::of(), $now()))
        ->toBeInstanceOf(Unchanged::class);
});
