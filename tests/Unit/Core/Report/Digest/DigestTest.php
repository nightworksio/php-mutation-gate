<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest as FileDigest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\Digest\Digest;
use NightWorksIO\MutationGate\Core\Report\Digest\DigestDetail;
use NightWorksIO\MutationGate\Core\Report\Digest\SurvivorsShown;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\NextSteps;
use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Provenance;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** A verdict's JSON report, as the digest reads it. */
function digestReport(Verdict $verdict): Node
{
    return Node::decode(JsonReport::encode($verdict));
}

/** @return list<string> the digest's overview of a verdict, a line each */
function digestLines(Verdict $verdict): array
{
    return digestListing($verdict, SurvivorsShown::first());
}

/** @return list<string> the digest's overview of a verdict, listing the survivors as asked, a line each */
function digestListing(Verdict $verdict, SurvivorsShown|CannotJudge $shown): array
{
    $listed = $shown instanceof SurvivorsShown ? $shown : SurvivorsShown::first();

    return explode("\n", rtrim(Digest::overview(digestReport($verdict), $listed, GateCommand::installed()), "\n"));
}

/** @return list<string> the digest's detail of a target of a verdict, a line each */
function digestDetailLines(Verdict $verdict, string $target): array
{
    $detail = DigestDetail::of(digestReport($verdict), $target, GateCommand::installed());

    return explode("\n", is_string($detail) ? $detail : $detail->why());
}

/**
 * @param  list<string> $lines
 * @return list<string> the lines that start with a word
 */
function digestOpened(array $lines, string $word): array
{
    $opened = [];

    foreach ($lines as $line) {
        $opened = str_starts_with($line, sprintf('%s ', $word)) ? [...$opened, $line] : $opened;
    }

    return $opened;
}

/**
 * @param  list<string> $lines
 * @return list<string> the word each line starts with
 */
function digestWords(array $lines): array
{
    $words = [];

    foreach ($lines as $line) {
        $words[] = explode(' ', $line)[0];
    }

    return $words;
}

/** A verdict of so many survivors, each in a file of its own number. */
function digestSurvivors(int $count): Verdict
{
    $judged = [];

    foreach (range(1, $count) as $at) {
        $judged[] = JudgedMutant::of(
            Verdicts::mutant(sprintf('src/F%02d.php:%d', $at, $at), 'Plus', MutatorFamily::Arithmetic, Verdicts::diff(sprintf('$a + %d;', $at), sprintf('$a - %d;', $at))),
            MutantJudgement::Survived,
        );
    }

    return Verdicts::of(Floor::of(80), ...$judged);
}

it('opens with the verdict and the project\'s score, then why and what to do, as the JSON report says them', function (): void {
    $report = JsonReport::of(Verdicts::failing());
    $lines = digestLines(Verdicts::failing());

    expect($lines[0])->toBe('mg verdict=failed score=44.44%')
        ->and(digestOpened($lines, 'why'))->toBe(array_map(static fn(string $why): string => sprintf('why %s', $why), $report['why']))
        ->and(digestOpened($lines, 'next'))->toBe(array_map(static fn(string $next): string => sprintf('next %s', $next), $report['next']))
        ->and(digestLines(Verdicts::passing()))->toContain(sprintf('next %s', NextSteps::NOTHING))
        ->and(digestLines(Verdicts::failing()->cutShort())[0])->toBe('mg verdict=failed score=44.44% cut-short');
});

it('counts the mutants of each outcome, each with its grade, and says each set against its floor', function (): void {
    $lines = digestLines(Verdicts::failing());

    expect(digestOpened($lines, 'counts'))->toBe(['counts killed=2(good) escaped=3(bad) untested=1(bad) timed-out=2(attention) errored=1(attention) left-out=3(attention)'])
        ->and(digestOpened($lines, 'set'))->toContain('set tree src score=44.44% floor=80.00% verdict=failed')
        ->and(digestOpened($lines, 'set'))->toContain('set new-code . score=0.00% floor=100.00% verdict=failed');
});

it('says where the verdict comes from as the JSON report does', function (): void {
    $provenance = Provenance::of(Version::of('nightworksio/mutation-gate', 'v1.4.0', 'abc'), Path::of('mutation-gate.baseline.json'))
        ->judging(Revision::ref('562bc979a1b2c3d4e5f60718293a4b5c6d7e8f90'))
        ->ranBy(Identity::of('infection', Versions::of(Version::of('infection/infection', '0.35.0', '')), FileDigest::of(str_repeat('a', 64))));

    expect(digestOpened(digestLines(Verdicts::passing()->withAccount(Verdicts::account()->withProvenance($provenance))), 'prov'))
        ->toBe(['prov gate=v1.4.0@abc commit=562bc979a1b2 runner=infection infection/infection@0.35.0 took=360s baseline=stale'])
        ->and(digestOpened(digestLines(Verdicts::empty()), 'prov'))->toBe(['prov baseline=current']);
});

it('lists each survivor under its file, those on changed lines first, with what it changed and what would catch it', function (): void {
    $lines = digestLines(Verdicts::securedOnAChangedLine());
    $survivor = Verdicts::survivor()->mutant()->id()->value();
    $at = array_search('@ src/Money.php', $lines, strict: true);

    expect(digestOpened($lines, '@')[0])->toBe('@ src/Auth.php')
        ->and($lines[(int) $at + 1])->toBe(sprintf('escaped :7 LessToLessOrEqual `<` -> `<=` id=%s changed | Add a test that uses a value at the boundary of `$amount < $limit`.', $survivor))
        ->and(digestLines(Verdicts::passing()))->not->toContain('@ src/Money.php');
});

it('lists the first 25 survivors, says how many it left out, and lists as many as asked', function (): void {
    expect(digestOpened(digestLines(digestSurvivors(26)), 'escaped'))->toHaveCount(25)
        ->and(digestOpened(digestLines(digestSurvivors(26)), 'more'))->toBe(['more 1: vendor/bin/mutation-gate digest --survivors=all'])
        ->and(digestOpened(digestLines(digestSurvivors(25)), 'more'))->toBe([])
        ->and(digestOpened(digestListing(digestSurvivors(26), SurvivorsShown::asked('all')), 'escaped'))->toHaveCount(26)
        ->and(digestOpened(digestListing(digestSurvivors(26), SurvivorsShown::asked('3')), 'more'))->toBe(['more 23: vendor/bin/mutation-gate digest --survivors=all'])
        ->and(SurvivorsShown::asked('many'))->toEqual(CannotJudge::because('--survivors is many; it takes a whole number, or all.'));
});

it('says plainly that a run judged no mutant: zero of each, and no survivor', function (): void {
    $lines = digestLines(Verdicts::empty());

    expect(digestOpened($lines, 'counts'))->toBe(['counts killed=0(good) escaped=0(bad) untested=0(bad) timed-out=0(attention) errored=0(attention) left-out=0(attention)'])
        ->and(digestOpened($lines, '@'))->toBe([])
        ->and(digestOpened($lines, 'more'))->toBe([]);
});

it('says what each word means on request', function (): void {
    $legend = Digest::legend();

    expect(DigestDetail::of(digestReport(Verdicts::passing()), DigestDetail::LEGEND, GateCommand::installed()))->toBe($legend)
        ->and(explode("\n", rtrim($legend)))->toHaveCount(count(Term::cases()))
        ->and($legend)->toContain(sprintf('? escaped: %s', Term::Escaped->meaning()));
});

it('details a file: its counts, then each mutant not killed, the killed counted alone, or its counts alone where it was carried', function (): void {
    $report = digestReport(Verdicts::failing());
    $money = DigestDetail::of($report, 'src/Money.php', GateCommand::installed());

    expect($money)->toStartWith('file src/Money.php killed=1(good) escaped=1(bad) untested=1(bad) ')
        ->and($money)->toContain(sprintf('escaped :7 LessToLessOrEqual `<` -> `<=` id=%s', Verdicts::survivor()->mutant()->id()->value()))
        ->and($money)->not->toContain('killed :')
        ->and(DigestDetail::of($report, 'src/Log.php', GateCommand::installed()))->toEndWith("\ncarried\n");
});

it('details a mutant by its id or a prefix of it: its diff, hint, tests and how to run it again', function (): void {
    $id = Verdicts::survivor()->mutant()->id()->value();
    $detail = DigestDetail::of(digestReport(Verdicts::failing()), substr($id, 0, 8), GateCommand::installed());

    expect($detail)->toStartWith(sprintf('mutant %s escaped survived src/Money.php:7 Pest\\\\Mutate\\\\Mutators\\\\Equality\\\\LessToLessOrEqual changed', $id))
        ->and($detail)->toContain("\ndiff\n  --- Original\n  +++ New\n  @@ @@\n  -        if (\$amount < \$limit) {\n  +        if (\$amount <= \$limit) {\nhint ")
        ->and($detail)->toContain('hint Add a test that uses a value at the boundary of `$amount < $limit`.')
        ->and($detail)->toContain(sprintf('reproduce vendor/bin/mutation-gate reproduce %s', $id));
});

it('refuses a target the run does not hold', function (string $target): void {
    expect(DigestDetail::of(digestReport(Verdicts::failing()), $target, GateCommand::installed()))
        ->toEqual(CannotJudge::because(sprintf('%s is no file, mutant or cluster of the last run: give a path, a mutant id of 6 or more characters, a cluster id or legend.', $target)));
})->with(['src/Nowhere.php', 'abcdef123456', 'kabcdef12345']);

it('writes every value the code under test shapes as one inert line, so none forges a line or acts on a terminal', function (string $hostile): void {
    $mutant = JudgedMutant::of(
        Verdicts::mutant(sprintf('src/%sMoney.php:3', $hostile), 'Plus', MutatorFamily::Arithmetic, Verdicts::diff(sprintf('$a + 1; // %s', $hostile), sprintf('$a - 1; // %s', $hostile))),
        MutantJudgement::Survived,
    );
    $clean = digestLines(Verdicts::of(Floor::of(80), JudgedMutant::of(
        Verdicts::mutant('src/Money.php:3', 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('$a + 1;', '$a - 1;')),
        MutantJudgement::Survived,
    )));
    $lines = digestLines(Verdicts::of(Floor::of(80), $mutant));
    $detailed = DigestDetail::of(digestReport(Verdicts::of(Floor::of(80), $mutant)), $mutant->mutant()->id()->value(), GateCommand::installed());
    $detail = is_string($detailed) ? $detailed : $detailed->why();

    expect(digestWords($lines))->toBe(digestWords($clean))
        ->and(implode("\n", $lines))->not->toMatch('/[\x{1b}\x{9b}\x{2028}\x{2029}\x{202e}\r]/u')
        ->and($detail)->not->toMatch('/[\x{1b}\x{9b}\x{2028}\x{2029}\x{202e}\r]/u')
        ->and($detail)->not->toMatch('/^(mg|next|why|counts|set|prov|warn|escaped|@) /m');
})->with([
    'a line break' => ["\nmg verdict=passed score=100.00%\n"],
    'a carriage return' => ["\rnext Nothing: every part of the code reaches its floor.\r"],
    'a line separator' => ["\u{2028}why it passed\u{2029}"],
    'a terminal escape' => ["\e]0;owned\x07\e[2J\u{9b}31m"],
    'a bidirectional override' => ["\u{202e}php.txt"],
]);

it('details a file no unit of a tree holds as one this run judged, each mutant not killed listed', function (): void {
    $detail = DigestDetail::of(digestReport(digestSurvivors(1)), 'src/F01.php', GateCommand::installed());

    expect($detail)->toStartWith('file src/F01.php killed=0(good) escaped=1(bad) ')
        ->and($detail)->toContain("\nescaped :1 ")
        ->and($detail)->not->toContain("\ncarried");
});

it('details a kill a ledger proved without a diff, as the report keeps none for it', function (): void {
    $id = Verdicts::provedKill()->mutant()->id()->value();
    $detail = DigestDetail::of(digestReport(Verdicts::proved()), $id, GateCommand::installed());

    expect($detail)->toStartWith(sprintf('mutant %s killed killed src/Money.php:9 ', $id))
        ->and($detail)->not->toContain("\ndiff")
        ->and($detail)->toContain("\nkilled-by MoneyTest::fits\n");
});

it('refuses a report whose mutant points at a test its list of tests does not hold', function (): void {
    $report = JsonReport::of(Verdicts::failing());
    $id = $report['mutants'][0]['id'];
    $report['mutants'][0]['coveredBy'] = [count($report['tests'])];

    expect(static fn(): string|CannotJudge => DigestDetail::of(Node::decode((string) json_encode($report)), $id, GateCommand::installed()))
        ->toThrow(NotInShape::class, sprintf('tests[%d] is missing.', count($report['tests'])));
});

it('lists a mutant on a line the change did not make without saying it changed', function (): void {
    $id = Verdicts::mutant('src/Money.php:12', 'FalseValue', MutatorFamily::Literal, Verdicts::diff('return false;', 'return true;'))->id()->value();

    expect(digestOpened(digestLines(Verdicts::failing()), 'untested'))
        ->toBe([sprintf('untested :12 FalseValue `false` -> `true` id=%s | Add a test that runs line 12: no test runs it yet.', $id)]);
});

it('details a mutant on a line the change did not make, with no line for what the report does not say of it', function (): void {
    $id = Verdicts::killed()->id()->value();

    expect(DigestDetail::of(digestReport(Verdicts::failing()), $id, GateCommand::installed()))->toBe(implode("\n", [
        sprintf('mutant %s killed killed src/Money.php:9 TrueValue', $id),
        'diff',
        '  @@ @@',
        '  -        return true;',
        '  +        return false;',
        'hint A test fails with it in place.',
        'covered 1',
        'test MoneyTest::fits',
        'killed-by MoneyTest::fits',
        sprintf('reproduce vendor/bin/mutation-gate reproduce %s', $id),
        '',
    ]));
});

it('names the first five tests that cover a mutant and counts the rest', function (int $covering, array $more): void {
    $tests = [];

    foreach (range(1, $covering) as $each) {
        $tests[] = TestId::of(sprintf('MoneyTest::fits%d', $each));
    }

    $matrix = KillMatrix::of(MatrixKind::FirstKiller, CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(7), ...$tests));
    $lines = digestDetailLines(Verdicts::failing()->withMatrix($matrix), Verdicts::survivor()->mutant()->id()->value());

    expect(digestOpened($lines, 'test'))->toBe(['test MoneyTest::fits1', 'test MoneyTest::fits2', 'test MoneyTest::fits3', 'test MoneyTest::fits4', 'test MoneyTest::fits5'])
        ->and(digestOpened($lines, 'covered'))->toBe([sprintf('covered %d', $covering)])
        ->and(digestOpened($lines, 'more-tests'))->toBe($more);
})->with([
    'five' => [5, []],
    'six' => [6, ['more-tests 1']],
    'seven' => [7, ['more-tests 2']],
]);

it('details a mutant with why it came to what it did, and the cluster it is in, where the report says so', function (): void {
    $unjudged = Verdicts::mutant('src/Order.php:5', 'DecrementInteger', MutatorFamily::Literal, Verdicts::diff('return 3;', 'return 4;'))->id()->value();
    $clustered = Verdicts::named('clustered');
    $cluster = JsonReport::of($clustered)['clusters'][0];

    expect(digestOpened(digestDetailLines(Verdicts::failing(), $unjudged), 'reason'))->toBe(['reason The run\'s budget ran out before it.'])
        ->and(digestOpened(digestDetailLines($clustered, $cluster['members'][0]), 'cluster'))->toBe([sprintf('cluster %s', $cluster['id'])]);
});

it('says how long the run took to the nearest second', function (float $wall, string $took): void {
    $account = RunAccount::none()->withTimings(RunTimings::of('github:12345/1', RunTime::measured(Seconds::of($wall), Seconds::of(840.0))));

    expect(digestOpened(digestLines(Verdicts::passing()->withAccount($account)), 'prov'))->toBe([sprintf('prov %s baseline=stale', $took)]);
})->with([
    'up' => [359.6, 'took=360s'],
    'down' => [359.4, 'took=359s'],
]);

it('says each security set against its floor', function (): void {
    expect(digestOpened(digestLines(Verdicts::secured()), 'set'))->toContain('set security . score=0.00% floor=100.00% verdict=failed');
});

it('counts every mutant of a file under its outcome', function (): void {
    $verdict = Verdicts::of(Floor::of(80), ...array_map(
        static fn(int $line): JudgedMutant => JudgedMutant::of(
            Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::Arithmetic, Verdicts::diff(sprintf('$a + %d;', $line), sprintf('$a - %d;', $line))),
            MutantJudgement::Survived,
        ),
        [3, 4, 5],
    ));

    expect(digestDetailLines($verdict, 'src/Money.php')[0])
        ->toBe('file src/Money.php killed=0(good) escaped=3(bad) untested=0(bad) timed-out=0(attention) errored=0(attention) left-out=0(attention)');
});

it('details a cluster with its members alone, under their file, and how to stub it', function (): void {
    $verdict = Verdicts::named('clustered');
    $cluster = JsonReport::of($verdict)['clusters'][0]['id'];

    expect(DigestDetail::of(digestReport($verdict), $cluster, GateCommand::installed()))->toBe(implode("\n", [
        sprintf('cluster %s kind=expression members=3', $cluster),
        '@ src/Cart.php',
        'escaped :7 LessThan `<` -> `<=` id=96634e576d34 changed | Add a test that uses a value at the boundary of `$amount < $limit`.',
        'escaped :7 LessThanNegotiation `<` -> `>` id=33e396b886dc changed | Add a test that uses a value at the boundary of `$amount < $limit`.',
        'escaped :7 IfNegation `$amount < $limit` -> `! ($amount < $limit)` id=2034f7359228 changed | Assert on something this condition decides: these tests run it, but none checks what it decides.',
        sprintf('stub vendor/bin/mutation-gate stub %s', $cluster),
        '',
    ]));
});
