<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest as FileDigest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
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

    return explode("\n", rtrim(Digest::overview(digestReport($verdict), $listed), "\n"));
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
        ->and(digestOpened(digestLines(digestSurvivors(26)), 'more'))->toBe(['more 1: mutation-gate digest --survivors=all'])
        ->and(digestOpened(digestLines(digestSurvivors(25)), 'more'))->toBe([])
        ->and(digestOpened(digestListing(digestSurvivors(26), SurvivorsShown::asked('all')), 'escaped'))->toHaveCount(26)
        ->and(digestOpened(digestListing(digestSurvivors(26), SurvivorsShown::asked('3')), 'more'))->toBe(['more 23: mutation-gate digest --survivors=all'])
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

    expect(DigestDetail::of(digestReport(Verdicts::passing()), DigestDetail::LEGEND))->toBe($legend)
        ->and(explode("\n", rtrim($legend)))->toHaveCount(count(Term::cases()))
        ->and($legend)->toContain(sprintf('? escaped: %s', Term::Escaped->meaning()));
});

it('details a file: its counts, then each mutant not killed, the killed counted alone, or its counts alone where it was carried', function (): void {
    $report = digestReport(Verdicts::failing());
    $money = DigestDetail::of($report, 'src/Money.php');

    expect($money)->toStartWith('file src/Money.php killed=1(good) escaped=1(bad) untested=1(bad) ')
        ->and($money)->toContain(sprintf('escaped :7 LessToLessOrEqual `<` -> `<=` id=%s', Verdicts::survivor()->mutant()->id()->value()))
        ->and($money)->not->toContain('killed :')
        ->and(DigestDetail::of($report, 'src/Log.php'))->toEndWith("\ncarried\n");
});

it('details a mutant by its id or a prefix of it: its diff, hint, tests and how to run it again', function (): void {
    $id = Verdicts::survivor()->mutant()->id()->value();
    $detail = DigestDetail::of(digestReport(Verdicts::failing()), substr($id, 0, 8));

    expect($detail)->toStartWith(sprintf("mutant %s escaped survived src/Money.php:7 Pest\\Mutate\\Mutators\\Equality\\LessToLessOrEqual changed\ndiff\n", $id))
        ->and($detail)->toContain(sprintf("diff\n%s", Verdicts::BOUNDARY))
        ->and($detail)->toContain('hint Add a test that uses a value at the boundary of `$amount < $limit`.')
        ->and($detail)->toContain(sprintf('reproduce vendor/bin/mutation-gate reproduce %s', $id));
});

it('details a cluster by its id, each member as the overview lists it', function (): void {
    $verdict = Verdicts::named('clustered');
    $cluster = JsonReport::of($verdict)['clusters'][0];
    $detail = DigestDetail::of(digestReport($verdict), $cluster['id']);

    expect($detail)->toStartWith(sprintf('cluster %s kind=%s members=%d', $cluster['id'], $cluster['kind'], count($cluster['members'])))
        ->and($detail)->toEndWith(sprintf("stub mutation-gate stub %s\n", $cluster['id']));
});

it('refuses a target the run does not hold', function (string $target): void {
    expect(DigestDetail::of(digestReport(Verdicts::failing()), $target))
        ->toEqual(CannotJudge::because(sprintf('%s is no file, mutant or cluster of the last run: give a path, a mutant id of 6 or more characters, a cluster id or legend.', $target)));
})->with(['src/Nowhere.php', 'abcdef123456', 'kabcdef12345']);

it('drops every control and format character a project wrote, so nothing it wrote can colour a terminal', function (): void {
    $hostile = JudgedMutant::of(
        Verdicts::mutant("src/\e[31mRed.php:3", 'Plus', MutatorFamily::Arithmetic, Verdicts::diff("\$a + 1; // \u{202E}evil", "\$a - 1; // \u{202E}evil")),
        MutantJudgement::Survived,
    );
    $digest = implode("\n", digestLines(Verdicts::of(Floor::of(80), $hostile)));

    expect($digest)->not->toContain("\e")
        ->and($digest)->not->toContain("\u{202E}")
        ->and($digest)->toContain('@ src/[31mRed.php');
});
