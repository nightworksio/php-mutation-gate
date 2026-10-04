<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Checked;
use NightWorksIO\MutationGate\Cli\Flow\SurvivorChecking;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedClock;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Psr\Clock\ClockInterface;

afterEach(function (): void {
    Scratch::sweep();
});

/** The fixture's mutants of Money and Held: of them, one survivor in each file. */
function checkedMutants(): Mutants
{
    return Mutants::of(...Flows::mutantsOf('src/Money.php'), ...Flows::mutantsOf('src/Held.php'));
}

/** The one survivor of a file among these mutants. */
function checkedSurvivor(Mutants $mutants, string $file): Mutant
{
    foreach ($mutants as $mutant) {
        if ($mutant->status() === MutantStatus::Survived && $mutant->location()->file()->value() === $file) {
            return $mutant;
        }
    }

    throw new LogicException(sprintf('No survivor in %s.', $file));
}

/** Where a survivor's mutated code is read for its check. */
function checkedAt(Mutant $mutant): string
{
    return Workspace::checkedMutant($mutant->id())->value();
}

/** The analyser the checks run: named fake, at 1.0.0. */
function checkedIdentity(): AnalyserIdentity
{
    return AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}'));
}

/** What the analyser finds in the originals: one error they already have. */
function checkedOriginals(): Findings
{
    return Findings::of(Finding::error(Path::of('src/Money.php'), 'known', 'The originals already have this.'));
}

/** @param array<string, Findings|OutOfScope|CannotJudge> $answers what the analyser answers of each mutant, by where it reads it */
function checkedBy(string $project, array $answers): RecordingChecker
{
    return new RecordingChecker(new StaticCheckerFake(checkedIdentity(), checkedOriginals(), $answers), $project);
}

/** @param array<string, Findings|OutOfScope|CannotJudge> $answers what the analyser answers of each mutant, by where it reads it */
function checkedByOneReadingDependents(string $project, array $answers): RecordingChecker
{
    return new RecordingChecker(
        new StaticCheckerFake(checkedIdentity(), checkedOriginals(), $answers, dependents: true),
        $project,
    );
}

/** The checks, in a project, by this analyser, of this runner's mutants, with the time a budget leaves. */
function checking(
    string $project,
    RecordingChecker|NoAnalyser $checker,
    ScriptedRunner $runner,
    Deadline|Unlimited $deadline,
    ClockInterface $clock = new TickingClock('2026-01-01T00:00:00Z', 1),
): SurvivorChecking {
    $ports = $checker instanceof RecordingChecker ? [$runner, $checker] : [$runner];

    return new SurvivorChecking(Flows::adapters($project, [], ...$ports), $clock, $deadline);
}

/** @return list<string> each warning the checks raise */
function checkedWarnings(Checked $checked): array
{
    return array_map(static fn(Warning $warning): string => $warning->text(), [...$checked->checks->warnings()]);
}

it('checks nothing where no analyser is configured, or no survivor is left once the flaky are set aside', function (): void {
    $project = Scratch::directory();
    $mutants = checkedMutants();
    $flaky = MutantIds::of(checkedSurvivor($mutants, 'src/Money.php')->id(), checkedSurvivor($mutants, 'src/Held.php')->id());
    $checker = checkedBy($project, []);

    $unconfigured = checking($project, NoAnalyser::configured(), ScriptedRunner::fixture(), Unlimited::time())->checked($mutants, MutantIds::none());
    $allFlaky = checking($project, $checker, ScriptedRunner::fixture(), Unlimited::time())->checked($mutants, $flaky);

    expect($unconfigured)->toEqual(new Checked($mutants, SurvivorChecks::none()))
        ->and($allFlaky)->toEqual(new Checked($mutants, SurvivorChecks::none()))
        ->and($checker->warmUps())->toBe([]);
});

it('kills a survivor whose check finds an error its original does not have, by that error in its file, and leaves one whose errors it has', function (): void {
    $project = Scratch::directory();
    $mutants = checkedMutants();
    $money = checkedSurvivor($mutants, 'src/Money.php');
    $held = checkedSurvivor($mutants, 'src/Held.php');
    $new = Finding::error(Path::of('src/Wallet.php'), 'return.type', 'Method Wallet::isLarge() should return bool.');
    $checker = checkedBy($project, [
        checkedAt($money) => Findings::of(...checkedOriginals(), ...Findings::of($new, Finding::error(Path::of('src/Money.php'), 'second', 'Also new.'))),
        checkedAt($held) => checkedOriginals(),
    ]);

    $checked = checking($project, $checker, ScriptedRunner::fixture(), Unlimited::time())->checked($mutants, MutantIds::none());
    $statuses = array_map(static fn(Mutant $mutant): string => $mutant->status()->value, [...$checked->mutants]);
    $texts = array_map(static fn(array $check): string => $check[2], $checker->checks());

    expect($checked->mutants)->toEqual($mutants->replacing(Mutants::of($money->rejected(Rejection::by('fake', $new)))))
        ->and($statuses)->toBe(['killed', 'killed-by-static-analysis', 'uncovered', 'timed-out', 'survived'])
        ->and(array_map(static fn(array $check): array => [$check[0], $check[1]], $checker->checks()))->toBe([
            ['src/Money.php', checkedAt($money)],
            ['src/Held.php', checkedAt($held)],
        ])
        ->and($texts[0])->toContain('return $amount >= 100;')
        ->and(str_contains($texts[0], 'return $amount > 100;'))->toBeFalse()
        ->and($texts[1])->toContain('return $amount - $amount;')
        ->and($checker->warmUps())->toEqual([Paths::none()])
        ->and(is_file(sprintf('%s/%s', $project, checkedAt($money))) || is_file(sprintf('%s/%s', $project, checkedAt($held))))->toBeFalse()
        ->and([...$checked->checks->histories()])->toHaveCount(1)
        ->and($checked->checks->histories()->of(checkedIdentity())->time()->checks())->toBe(2)
        ->and($checked->checks->histories()->of(checkedIdentity())->time()->seconds())->toEqual(Seconds::of(2.0))
        ->and([...$checked->checks->histories()->of(checkedIdentity())])->toBe([])
        ->and(checkedWarnings($checked))->toBe([]);
});

it('lists the files a survivor can break where it changes what its file declares and the analyser reads them, and none where it changes a body', function (): void {
    $library = 'tests/Contract/Runner/phpunit-fixture/library/src';
    $files = [
        'src/Money.php' => (string) file_get_contents(Tree::at(sprintf('%s/Money.php', $library))),
        'src/Held.php' => (string) file_get_contents(Tree::at(sprintf('%s/Held.php', $library))),
        'src/Wallet.php' => "<?php\n\nnamespace Library;\n\nfinal class Wallet\n{\n    public function large(Money \$money): bool { return \$money->isLarge(1); }\n}\n",
    ];
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $checkout = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [Revision::workingTree()->name() => $files]);
    $retyped = ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(
        str_replace('public function isLarge', 'protected function isLarge', $files['src/Money.php']),
    )));
    $bodies = checkedByOneReadingDependents($project, []);
    $declarations = checkedByOneReadingDependents($project, []);
    $unread = checkedBy($project, []);
    $clock = new TickingClock('2026-01-01T00:00:00Z', 1);

    new SurvivorChecking(Flows::adapters($project, [], ScriptedRunner::fixture(), $bodies, $checkout), $clock, Unlimited::time())
        ->checked(checkedMutants(), MutantIds::none());
    new SurvivorChecking(Flows::adapters($project, [], $retyped, $declarations, $checkout), $clock, Unlimited::time())
        ->checked(checkedMutants(), MutantIds::none());
    new SurvivorChecking(Flows::adapters($project, [], $retyped, $unread, $checkout), $clock, Unlimited::time())
        ->checked(checkedMutants(), MutantIds::none());

    expect($bodies->dependents())->toBe([[], []])
        ->and($declarations->dependents())->toBe([['src/Wallet.php'], []])
        ->and($unread->dependents())->toBe([[], []]);
});

it('reads what a printed survivor declares against its file printed, not as the file is written', function (): void {
    $library = 'tests/Contract/Runner/phpunit-fixture/library/src';
    $money = (string) file_get_contents(Tree::at(sprintf('%s/Money.php', $library)));
    $files = [
        'src/Money.php' => $money,
        'src/Wallet.php' => "<?php\n\nnamespace Library;\n\nfinal class Wallet\n{\n    public function large(Money \$money): bool { return \$money->isLarge(1); }\n}\n",
    ];
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $print = str_replace('): bool', ') : bool', $money);
    $printed = ScriptedRunner::fixture()->checking(Checkable::printed(
        Contents::of($print),
        Contents::of(str_replace('return $amount > 100;', 'return $amount >= 100;', $print)),
    ));
    $mutants = Flows::mutantsOf('src/Money.php');
    $survivor = checkedSurvivor($mutants, 'src/Money.php');
    $checker = checkedByOneReadingDependents($project, [
        Workspace::checkedOriginal($survivor->id())->value() => checkedOriginals(),
        checkedAt($survivor) => checkedOriginals(),
    ]);
    $checkout = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [Revision::workingTree()->name() => $files]);

    new SurvivorChecking(Flows::adapters($project, [], $printed, $checker, $checkout), new TickingClock('2026-01-01T00:00:00Z', 1), Unlimited::time())
        ->checked($mutants, MutantIds::none());

    expect($checker->dependents())->toBe([[], []]);
});

it('leaves a flaky survivor to its second answer, unchecked', function (): void {
    $project = Scratch::directory();
    $mutants = checkedMutants();
    $held = checkedSurvivor($mutants, 'src/Held.php');
    $checker = checkedBy($project, [checkedAt(checkedSurvivor($mutants, 'src/Money.php')) => checkedOriginals()]);

    checking($project, $checker, ScriptedRunner::fixture(), Unlimited::time())->checked($mutants, MutantIds::of($held->id()));

    expect(array_map(static fn(array $check): string => $check[0], $checker->checks()))->toBe(['src/Money.php']);
});

it('leaves every survivor unchecked where the analyser cannot say who it is, or cannot run over the originals', function (): void {
    $project = Scratch::directory();
    $mutants = checkedMutants();
    $unnamed = new RecordingChecker(new StaticCheckerFake(CannotJudge::because('No version.'), checkedOriginals(), []), $project);
    $cold = new RecordingChecker(new StaticCheckerFake(checkedIdentity(), CannotJudge::because('It crashed.'), []), $project);

    $unidentified = checking($project, $unnamed, ScriptedRunner::fixture(), Unlimited::time())->checked($mutants, MutantIds::none());
    $unwarmed = checking($project, $cold, ScriptedRunner::fixture(), Unlimited::time())->checked($mutants, MutantIds::none());

    expect(checkedWarnings($unidentified))->toBe([
        'Static analysis left 2 survivors unchecked, as the analyser could not say its version: src/Held.php, src/Money.php.',
    ])
        ->and($unnamed->warmUps())->toBe([])
        ->and(checkedWarnings($unwarmed))->toBe([
            'Static analysis left 2 survivors unchecked, as the analyser\'s run over the original files failed: src/Held.php, src/Money.php.',
        ])
        ->and($cold->checks())->toBe([])
        ->and([$unidentified->mutants, $unwarmed->mutants])->toEqual([$mutants, $mutants]);
});

it('leaves a survivor unchecked, and says why, where its runner cannot give it, its file is out of scope, or its check cannot run', function (): void {
    $project = Scratch::directory();
    $mutants = checkedMutants();
    $money = checkedSurvivor($mutants, 'src/Money.php');
    $checker = checkedBy($project, [checkedAt($money) => OutOfScope::of(Path::of('src/Money.php'))]);
    $refusing = ScriptedRunner::fixture()->checking(CannotJudge::because('Its diff does not apply.'));

    $ungiven = checking($project, checkedBy($project, []), $refusing, Unlimited::time())->checked($mutants, MutantIds::none());
    $unchecked = checking($project, $checker, ScriptedRunner::fixture(), Unlimited::time())->checked($mutants, MutantIds::none());

    expect(checkedWarnings($ungiven))->toBe([
        'Static analysis left 2 survivors unchecked, as the runner could not give their mutated code: src/Held.php, src/Money.php.',
    ])
        ->and(checkedWarnings($unchecked))->toBe([
            'Static analysis left 1 survivor unchecked, as their files are outside the paths the analyser analyses: src/Money.php.',
            'Static analysis left 1 survivor unchecked, as the analyser could not check them: src/Held.php. '
            . '.mutation-gate/staticcheck/mutants/8705b7dc7d27.php is no mutant the fake was told about.',
        ])
        ->and($unchecked->mutants)->toEqual($mutants)
        ->and($unchecked->checks->histories()->of(checkedIdentity())->time()->checks())->toBe(2);
});

it('judges a printed survivor against its original printed the same way, analysed once for each file', function (): void {
    $project = Scratch::directory();
    $mutants = checkedMutants();
    $money = checkedSurvivor($mutants, 'src/Money.php');
    $diff = "@@ @@\n-return \$amount > 100;\n+return \$amount > 101;";
    $again = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Increment', $diff, 0),
        'Increment-16',
        Location::of(Path::of('src/Money.php'), $money->location()->start(), $money->location()->end()),
        Mutation::of('Increment', MutatorFamily::Literal, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $held = checkedSurvivor($mutants, 'src/Held.php');
    $printed = ScriptedRunner::fixture()->checking(Checkable::printed(Contents::of('<?php // printed'), Contents::of('<?php // mutant')));
    $new = Finding::error(Path::of('src/Money.php'), 'new', 'New.');
    $checker = checkedBy($project, [
        Workspace::checkedOriginal($money->id())->value() => checkedOriginals(),
        Workspace::checkedOriginal($held->id())->value() => Findings::none(),
        checkedAt($money) => Findings::of($new),
        checkedAt($again) => checkedOriginals(),
    ]);

    $checked = checking($project, $checker, $printed, Unlimited::time())->checked($mutants->with($again), MutantIds::none());

    expect($checker->checks())->toBe([
        ['src/Money.php', Workspace::checkedOriginal($money->id())->value(), '<?php // printed'],
        ['src/Money.php', checkedAt($money), '<?php // mutant'],
        ['src/Held.php', Workspace::checkedOriginal($held->id())->value(), '<?php // printed'],
        ['src/Money.php', checkedAt($again), '<?php // mutant'],
    ])
        ->and($checked->mutants)->toEqual($mutants->with($again)->replacing(Mutants::of($money->rejected(Rejection::by('fake', $new)))))
        ->and(checkedWarnings($checked))->toBe([
            'Static analysis left 1 survivor unchecked, as their files analyse differently once printed as the runner prints its mutants: src/Held.php.',
        ]);
});

it('runs nothing where the time budget has already run out, and leaves every survivor unchecked', function (): void {
    $project = Scratch::directory();
    $checker = checkedBy($project, []);
    $spent = Deadline::after(new DateTimeImmutable('2026-01-01T00:00:00Z'), Seconds::of(0.0));

    $checked = checking($project, $checker, ScriptedRunner::fixture(), $spent)->checked(checkedMutants(), MutantIds::none());

    expect(checkedWarnings($checked))->toBe([
        'Static analysis left 2 survivors unchecked, as the time budget ran out before their checks: src/Held.php, src/Money.php.',
    ])
        ->and($checker->warmUps())->toBe([])
        ->and($checker->checks())->toBe([]);
});

it('starts a check only where the time left has room for it, as long as the run over the originals took before any check is timed, and the checks\' mean after', function (
    ScriptedClock $clock,
    bool $printed,
    string $checked,
): void {
    $project = Scratch::directory();
    $mutants = checkedMutants();
    $money = checkedSurvivor($mutants, 'src/Money.php');
    $held = checkedSurvivor($mutants, 'src/Held.php');
    $checker = checkedBy($project, [
        checkedAt($money) => checkedOriginals(),
        checkedAt($held) => checkedOriginals(),
        Workspace::checkedOriginal($money->id())->value() => checkedOriginals(),
        Workspace::checkedOriginal($held->id())->value() => checkedOriginals(),
    ]);
    $runner = $printed
        ? ScriptedRunner::fixture()->checking(Checkable::printed(Contents::of('<?php // printed'), Contents::of('<?php // mutant')))
        : ScriptedRunner::fixture();
    $deadline = Deadline::after(new DateTimeImmutable('2026-01-01T00:00:00Z'), Seconds::of(10.0));
    checking($project, $checker, $runner, $deadline, $clock)->checked($mutants, MutantIds::none());

    expect(array_map(static fn(array $check): string => $check[1], $checker->checks()))->toBe(array_map(
        static fn(string $file): string => $file === 'money' ? checkedAt($money) : checkedAt($held),
        $checked === '' ? [] : explode(' ', $checked),
    ));
})->with([
    // read before the warm-up, its start and end, then before each check, its start and end
    'a warm-up of 9s leaves 1s, too little for a first check' => [
        new ScriptedClock('2026-01-01T00:00:00Z', 0, 0, 9, 9),
        false,
        '',
    ],
    'a warm-up of 1s leaves room for the first check, whose 6s leave too little for another' => [
        new ScriptedClock('2026-01-01T00:00:00Z', 0, 0, 1, 1, 1, 7, 7),
        false,
        'money',
    ],
    'every check fits where each takes as long as the warm-up' => [
        new ScriptedClock('2026-01-01T00:00:00Z', 0, 0, 1, 1, 1, 2, 2, 2, 3),
        false,
        'money held',
    ],
    'a printed file\'s first survivor needs room for its print and its mutant' => [
        new ScriptedClock('2026-01-01T00:00:00Z', 0, 0, 1, 9),
        true,
        '',
    ],
]);

it('checks a printed file\'s first survivor where the time left has room for exactly its print and its mutant', function (): void {
    $project = Scratch::directory();
    $mutants = Flows::mutantsOf('src/Money.php');
    $money = checkedSurvivor($mutants, 'src/Money.php');
    $checker = checkedBy($project, [
        Workspace::checkedOriginal($money->id())->value() => checkedOriginals(),
        checkedAt($money) => checkedOriginals(),
    ]);
    $printed = ScriptedRunner::fixture()->checking(Checkable::printed(Contents::of('<?php // printed'), Contents::of('<?php // mutant')));
    $deadline = Deadline::after(new DateTimeImmutable('2026-01-01T00:00:00Z'), Seconds::of(10.0));
    // before the warm-up, its start and end of 1s; then before the survivor, with 2s left, room for two checks of 1s
    $clock = new ScriptedClock('2026-01-01T00:00:00Z', 0, 0, 1, 8, 8, 9, 9, 10);

    checking($project, $checker, $printed, $deadline, $clock)->checked($mutants, MutantIds::none());

    expect(array_map(static fn(array $check): string => $check[1], $checker->checks()))->toBe([
        Workspace::checkedOriginal($money->id())->value(),
        checkedAt($money),
    ]);
});

it('needs room for one check alone for a printed file\'s later survivors, whose print is analysed', function (): void {
    $project = Scratch::directory();
    $mutants = Flows::mutantsOf('src/Money.php');
    $money = checkedSurvivor($mutants, 'src/Money.php');
    $diff = "@@ @@\n-return \$amount > 100;\n+return \$amount > 101;";
    $again = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Increment', $diff, 0),
        'Increment-16',
        $money->location(),
        Mutation::of('Increment', MutatorFamily::Literal, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $checker = checkedBy($project, [
        Workspace::checkedOriginal($money->id())->value() => checkedOriginals(),
        checkedAt($money) => checkedOriginals(),
        checkedAt($again) => checkedOriginals(),
    ]);
    $printed = ScriptedRunner::fixture()->checking(Checkable::printed(Contents::of('<?php // printed'), Contents::of('<?php // mutant')));
    $deadline = Deadline::after(new DateTimeImmutable('2026-01-01T00:00:00Z'), Seconds::of(10.0));
    // before the warm-up, its start and end; before the first survivor, its print's and its mutant's
    // checks; then before the second, with 1s left, room for one more check of 1s alone
    $clock = new ScriptedClock('2026-01-01T00:00:00Z', 0, 0, 1, 1, 1, 2, 2, 3, 9, 9, 10);

    checking($project, $checker, $printed, $deadline, $clock)->checked($mutants->with($again), MutantIds::none());

    expect(array_map(static fn(array $check): string => $check[1], $checker->checks()))->toBe([
        Workspace::checkedOriginal($money->id())->value(),
        checkedAt($money),
        checkedAt($again),
    ]);
});

it('leaves a survivor unchecked where its code cannot be written for its check', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/staticcheck/mutants', 'a file where the directory goes');
    $checker = checkedBy($project, []);

    $mutants = checkedMutants();
    $unwritten = static fn(string $file): string => sprintf(
        'Static analysis left 1 survivor unchecked, as the analyser could not check them: %s. %s/%s could not be written.',
        $file,
        $project,
        checkedAt(checkedSurvivor($mutants, $file)),
    );

    $checked = checking($project, $checker, ScriptedRunner::fixture(), Unlimited::time())->checked($mutants, MutantIds::none());

    expect(checkedWarnings($checked))->toBe([$unwritten('src/Money.php'), $unwritten('src/Held.php')])
        ->and($checker->checks())->toBe([]);
});
