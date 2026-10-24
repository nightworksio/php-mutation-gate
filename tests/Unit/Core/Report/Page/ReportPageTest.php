<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Page\ReportPage;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Provenance;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Killings;
use NightWorksIO\MutationGate\Tests\Support\Secured;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** The page of a verdict, with a style and a script that stand for the shipped ones. */
function pageOf(Verdict $verdict): string
{
    return ReportPage::html(JsonReport::of($verdict), 'body { color: red; }', 'void 0;');
}

/** What the page's navigation reads: the JSON in its data element. */
function pageData(string $page): string
{
    preg_match('~<script type="application/json" id="data">(.*?)</script>~s', $page, $found);

    return $found[1] ?? '';
}

/**
 * What each mutant the page shows came to, by its outcome, each the gate's judgement.
 *
 * @return array<string, list<mixed>>
 */
function pageOutcomes(string $page): array
{
    $data = pageData($page);
    $outcomes = [];

    foreach (array_keys(Decoded::column($data, 'path', 'files')) as $file) {
        $judgements = Decoded::column($data, 'judgement', 'files', $file, 'mutants');

        foreach (Decoded::column($data, 'outcome', 'files', $file, 'mutants') as $at => $outcome) {
            $outcomes[sprintf('%s', json_encode($outcome))][] = $judgements[$at];
        }
    }

    ksort($outcomes);

    return $outcomes;
}

/** The place in the page's list of files of the file at a path. */
function pageFile(string $data, string $path): int
{
    $files = Decoded::at($data, 'files');

    foreach (is_array($files) ? $files : [] as $at => $file) {
        if (is_array($file) && $file['path'] === $path) {
            return (int) $at;
        }
    }

    return -1;
}

/**
 * What each mutant of the file at a place in the page's data holds at a key, by its line.
 *
 * @return array<int, mixed>
 */
function pageByLine(string $data, int $file, string $key): array
{
    $mutants = Decoded::at($data, 'files', $file, 'mutants');
    $values = [];

    foreach (is_array($mutants) ? $mutants : [] as $mutant) {
        if (is_array($mutant) && is_int($mutant['line'])) {
            $values[$mutant['line']] = $mutant[$key] ?? null;
        }
    }

    return $values;
}

/** The part of a page from the first place it opens to where it next closes. */
function pagePart(string $page, string $open, string $close): string
{
    $from = (int) strpos($page, $open);

    return substr($page, $from, (int) strpos($page, $close, $from) - $from + strlen($close));
}

/**
 * Survivors at the first two lines of `src/A.php` and at the fifth of
 * `src/B.php`, a line the change made; kills at its sixth, also made by the
 * change, at the third of `src/A.php` and at the first of `src/C.php`.
 */
function onChangedLines(): Verdict
{
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/B.php'), Lines::of(Line::of(5), Line::of(6)));
    $judged = [];

    foreach (['src/A.php:1' => MutantJudgement::Survived, 'src/A.php:2' => MutantJudgement::Survived, 'src/B.php:5' => MutantJudgement::Survived, 'src/B.php:6' => MutantJudgement::Killed, 'src/A.php:3' => MutantJudgement::Killed, 'src/C.php:1' => MutantJudgement::Killed] as $at => $judgement) {
        $judged[] = JudgedMutant::of(Verdicts::mutant($at, 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('$a + 1', '$a - 1')), $judgement)->within($reach);
    }

    return Verdicts::of(Floor::of(80), ...$judged);
}

/** A mutant of every judgement, each judged in this run. */
function everyJudged(): Verdict
{
    $judged = [];

    foreach (Verdicts::everyJudgement() as $mutant) {
        $judged = $mutant instanceof JudgedMutant ? [...$judged, $mutant] : $judged;
    }

    return Verdicts::of(Floor::of(80), ...$judged);
}

it('says the verdict and why on top: each set below its floor, each failure, and the project\'s score', function (): void {
    $page = pageOf(Verdicts::failing());
    $report = JsonReport::of(Verdicts::failing());

    $why = pagePart($page, '<ul class="why">', '</ul>');

    expect($page)->toContain('<h1><span class="status status--failed">Failed</span></h1>')
        ->and($why)->toContain(sprintf('<li>%s</li>', htmlspecialchars($report['trees'][0]['said'])))
        ->and($why)->toContain(sprintf('<li>%s</li>', htmlspecialchars($report['newCode'][0]['said'])))
        ->and($why)->toContain(sprintf('<li>%s</li>', htmlspecialchars($report['failures'][0], ENT_QUOTES | ENT_HTML5)))
        ->and($why)->toContain('<li>The project scores 44.44%.</li>')
        ->and(pagePart(pageOf(Verdicts::secured()), '<ul class="why">', '</ul>'))->toContain('<li>Security scores 0.00%, below its floor of 100.00%.</li>')
        ->and(pageOf(Verdicts::passing()))->toContain('<span class="status status--passed">Passed</span>')
        ->and(pageOf(Verdicts::passing()))->toContain('<ul class="why"><li>The project scores 100.00%.</li></ul>');
});

it('says a run cannot judge, and why, on top', function (): void {
    $page = pageOf(Verdicts::named('cannot judge'));

    expect($page)->toContain('<span class="status status--cannot-judge">Cannot judge</span>')
        ->and(pagePart($page, '<ul class="why">', '</ul>'))->toContain(sprintf('<li>%s</li>', Verdicts::UNJUDGED))
        ->and($page)->toContain(sprintf('<section><h3>Cannot judge</h3><ul><li>%s</li></ul></section>', Verdicts::UNJUDGED));
});

it('says a run was sampled, or stopped before it judged every mutant, beside its verdict', function (): void {
    $page = pageOf(Verdicts::failing()->cutShort());

    expect($page)->toContain('Failed</span> <span class="qualifier">cut short</span></h1>')
        ->and($page)->toContain('<li>The run stopped before it judged every mutant.</li>')
        ->and(pageOf(Verdicts::failing()))->not->toContain('qualifier');
});

it('counts the mutants of each outcome, and those the score leaves out', function (): void {
    $page = pageOf(everyJudged());

    expect($page)->toContain('<li class="tile tile--killed"><span class="tile-count">2</span><span class="tile-label">Killed</span></li>')
        ->and($page)->toContain('<li class="tile tile--escaped"><span class="tile-count">3</span><span class="tile-label">Escaped</span></li>')
        ->and($page)->toContain('<li class="tile tile--untested"><span class="tile-count">1</span><span class="tile-label">Untested</span></li>')
        ->and($page)->toContain('<li class="tile tile--timed-out"><span class="tile-count">2</span><span class="tile-label">Timed out</span></li>')
        ->and($page)->toContain('<li class="tile tile--errored"><span class="tile-count">1</span><span class="tile-label">Errored</span></li>')
        ->and($page)->toContain('<p class="aside">12 judged, 3 left out of the score.</p>')
        ->and($page)->toContain('<span class="tile-label">Killed</span></li><li class="tile tile--escaped">');
});

it('shows each tree, new-code and security set, its score as a bar against its floor where it has both', function (): void {
    $sets = pagePart(pageOf(Verdicts::secured()), '<ul class="sets">', '</ul>');

    expect($sets)->toContain('</div></li><li class="set set--exempt"><p class="set-said">app/Legacy is exempt: Replaced by the new billing module</p></li><li class="set set--nothing-to-mutate"><p class="set-said">src/Empty has nothing to mutate.</p></li><li class="set set--passed">')
        ->and($sets)->toContain('<li class="set set--failed"><p class="set-said">New code scores 0.00%, below its floor of 100.00%.</p>')
        ->and($sets)->toContain('<li class="set set--failed"><p class="set-said">Security scores 0.00%, below its floor of 100.00%.</p>')
        ->and($sets)->toContain('<li class="set set--passed"><p class="set-said">Security in packages/billing scores 100.00% against its floor of 50.00%.</p>');
});

it('shows each mutant with what it came to and the gate\'s own judgement beside it', function (): void {
    expect(pageOutcomes(pageOf(everyJudged())))->toEqual([
        '"errored"' => ['errored'],
        '"escaped"' => ['flaky', 'unjudged', 'survived'],
        '"killed"' => ['killed', 'killed-by-static-analysis'],
        '"left-out"' => ['ignored', 'ignored-by-marker', 'equivalent'],
        '"timed-out"' => ['too-slow-to-judge', 'killed-by-timeout'],
        '"untested"' => ['uncovered'],
    ]);
});

it('gives each mutant what its pane shows: its change, the tests that killed it, or how many cover it and what would kill it', function (): void {
    $data = pageData(pageOf(Verdicts::failing()));
    $money = pageFile($data, 'src/Money.php');
    $id = Verdicts::survivor()->mutant()->id()->value();

    expect(Decoded::at($data, 'files', $money, 'mutants', 0))->toMatchArray([
        'id' => $id,
        'line' => 7,
        'outcome' => 'escaped',
        'judgement' => 'survived',
        'diff' => Verdicts::BOUNDARY,
        'killedBy' => [],
        'reproduce' => sprintf('vendor/bin/mutation-gate reproduce %s', $id),
    ])->and(Decoded::at($data, 'files', $money, 'counts'))->toMatchArray(['escaped' => 1, 'untested' => 1, 'killed' => 1]);
});

it('names a survivor in the overview by where it is, linking to it, those on changed lines first', function (): void {
    $page = pageOf(Verdicts::securedOnAChangedLine());
    $survivors = substr($page, (int) strpos($page, '<ol class="survivor-list">'));

    expect($survivors)->toStartWith(sprintf('<ol class="survivor-list"><li><a href="#m-%s"><code>src/Auth.php:3</code></a>', JsonReport::of(Verdicts::securedOnAChangedLine())['security'][0]['mutants'][0]))
        ->and($survivors)->toContain('<span class="chip">changed line</span>')
        ->and(pageOf(Verdicts::passing()))->toContain('<p class="empty">No survivors: every mutant the score counts was killed.</p>');
});

it('names the survivors on changed lines first, and counts the mutants on changed lines and those of them surviving', function (): void {
    $page = pageOf(onChangedLines());
    $survivors = pagePart($page, '<section class="card survivors"', '</section>');
    preg_match_all('~<code>([^<]+)</code>~', $survivors, $named);

    expect($named[1])->toBe(['src/B.php:5', 'src/A.php:1', 'src/A.php:2'])
        ->and(substr_count($survivors, '</li><li>'))->toBe(2)
        ->and(substr_count($survivors, 'changed line'))->toBe(1)
        ->and($survivors)->toContain('<span class="judgement">survived</span> <span class="chip">changed line</span></li><li>')
        ->and($survivors)->toContain('<span class="judgement">survived</span></li></ol>' . "\n</section>")
        ->and(pagePart($page, '<section class="card files"', '</section>'))->toBe(<<<'HTML'
            <section class="card files" aria-labelledby="files-title">
            <h2 id="files-title">Files judged <span class="count">3</span></h2>
            <p class="aside">2 mutants on changed lines, 1 of them surviving.</p>
            <ol class="file-list"><li><a href="#f-0"><code>src/A.php</code></a> <span class="tag tag--escaped">2 surviving</span></li><li><a href="#f-1"><code>src/B.php</code></a> <span class="tag tag--escaped">1 surviving</span></li></ol>
            </section>
            HTML)
        ->and(pageOf(Verdicts::passing()))->toContain('<p class="aside">No mutant is on a changed line.</p>');
});

it('names in the page\'s data each test that killed a mutant once, and each mutant points at its killers there', function (): void {
    $data = pageData(pageOf(Killings::verdict(MatrixKind::Full)));

    expect(Decoded::at($data, 'tests'))->toBe(['tests/ATest.php::it does A', 'tests/BTest.php::it does B with data set #0', 'tests/DTest.php::it does D'])
        ->and(pageByLine($data, 0, 'killedBy'))->toMatchArray([1 => [0], 2 => [0, 1], 4 => [2]]);
});

it('gives a mutant its reason where it has one, and no diff where none was kept', function (): void {
    $data = pageData(pageOf(everyJudged()));
    $order = pageFile($data, 'src/Order.php');
    $proved = Verdict::of(TreeVerdicts::of(TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::kills(Verdicts::provedKill()),
        Uncovered::Count,
    )));
    $carried = Decoded::at(pageData(pageOf($proved)), 'files', 0, 'mutants', 0);

    expect(pageByLine($data, $order, 'reason'))->toMatchArray([5 => 'The run\'s budget ran out before it.', 3 => null])
        ->and(is_array($carried) ? $carried : [])->toHaveKey('killedBy')
        ->and(is_array($carried) ? $carried : [])->not->toHaveKey('diff');
});

it('keeps a file whose result was carried to its counts, and lists the files with the most survivors first', function (): void {
    $data = pageData(pageOf(Verdicts::failing()));
    $log = pageFile($data, 'src/Log.php');

    expect(Decoded::at($data, 'files', $log, 'carried'))->toBeTrue()
        ->and(Decoded::at($data, 'files', $log, 'mutants'))->toBe([])
        ->and(Decoded::at($data, 'files', $log, 'counts'))->toMatchArray(['errored' => 1, 'killed' => 1, 'left-out' => 3])
        ->and(Decoded::column($data, 'path', 'files'))->toBe(['src/Order.php', 'src/Money.php', 'src/Log.php'])
        ->and(Decoded::column($data, 'survivors', 'files'))->toBe([3, 2, 0]);
});

it('says plainly that a run judged no mutant', function (): void {
    $page = pageOf(Verdicts::empty());

    expect($page)->toContain('<p class="empty">No mutant was judged.</p>')
        ->and($page)->toContain('<p class="empty">No file has a survivor.</p>')
        ->and(Decoded::at(pageData($page), 'files'))->toBe([]);
});

it('says where the verdict comes from: the gate, the commit, the runner, how long it took and the baseline file', function (): void {
    $provenance = Provenance::of(Version::of('nightworksio/mutation-gate', 'v1.4.0', 'abc'), Path::of('mutation-gate.baseline.json'))
        ->judging(Revision::ref('562bc979a1b2c3d4e5f60718293a4b5c6d7e8f90'))
        ->ranBy(Identity::of('infection', Versions::of(Version::of('infection/infection', '0.35.0', '')), Digest::of(str_repeat('a', 64))));
    $verdict = Verdicts::passing()->withAccount(Verdicts::account()->withProvenance($provenance));
    $page = pageOf($verdict);

    expect($page)->toContain('<li>mutation-gate <span title="abc">v1.4.0</span></li>')
        ->and($page)->toContain('<li>commit <code title="562bc979a1b2c3d4e5f60718293a4b5c6d7e8f90">562bc979a1b2</code></li>')
        ->and($page)->toContain('<li>infection <span class="packages">infection/infection 0.35.0</span></li>')
        ->and($page)->toContain('<li>took ')
        ->and($page)->toContain('mutation-gate.baseline.json is stale: a score rose above its floor')
        ->and(pageOf(Verdicts::empty()))->toContain('<footer class="provenance"><ul><li><span class="baseline baseline--current">the baseline is current</span></li></ul></footer>');
});

it('bars a set held to no floor with no floor marked, and says the baseline holds none for it', function (): void {
    $unfloored = Secured::tree('src', Package::at(Path::root()), Undeclared::floor(), Secured::mutant(MutantJudgement::Killed), Secured::mutant(MutantJudgement::Survived, 2));
    $page = pageOf(Verdict::of(TreeVerdicts::of($unfloored)));

    expect($page)->toContain('<div class="bar" role="img" aria-label="Score 50.00%">' . "\n" . '<span class="bar-fill" style="width: 50.00%"></span>' . "\n</div>")
        ->and($page)->not->toContain('bar-floor')
        ->and($page)->toContain('<span class="baseline baseline--missing">the baseline holds no floor for a set with a score</span>');
});

it('says what each suite alone kills, and why each is a lower bound where it is', function (): void {
    $page = pageOf(Killings::suited(MatrixKind::FirstKiller));

    expect($page)->toContain('<section><h3>Suites</h3><ul><li>unit alone kills at least 66.66% of the 3 mutants its tests cover.</li><li>feature alone kills at least 50.00% of the 2 mutants its tests cover.</li><li>Each is a lower bound, from first killers: `mutation-gate run --kill-matrix=full` makes it exact.</li></ul></section>')
        ->and(pageOf(Killings::suited(MatrixKind::Full)))->not->toContain('lower bound')
        ->and(pageOf(Killings::unplaced()))->toContain('<section><h3>Suites</h3><ul><li>The runner named no test, so no suite can be scored.</li></ul></section>');
});

it('folds away what the run warns of, why the change reached what it did and what failed it, or says it has nothing more', function (): void {
    $page = pageOf(Verdicts::failing());

    expect($page)->toContain('<section><h3>Failures</h3>')
        ->and($page)->toContain('<section><h3>Warnings</h3><ul><li>src/Kernel.php is run by 412 of 430 tests and nothing holds it.</li></ul></section>')
        ->and($page)->toContain('<section><h3>Reach</h3>')
        ->and(pageOf(Verdicts::named('accounted')))->toContain('<section><h3>Timings</h3><ul><li>The run took 6m of wall time and 14m of runner time.</li><li>The plan took 40s, from 2026-09-30T11:50:00Z.</li>')
        ->and(pageOf(Verdicts::empty()))->toContain('<p class="empty">The run left nothing more to say.</p>');
});

it('escapes everything a project writes, in the page and in its data', function (): void {
    $hostile = JudgedMutant::of(
        Verdicts::mutant('src/<b>x</b>.php:3', 'Pl"u\'s', MutatorFamily::Arithmetic, Verdicts::diff('</script><script>alert(1)</script>', '& "x"')),
        MutantJudgement::Survived,
    );
    $page = pageOf(Verdicts::of(Floor::of(80), $hostile));

    expect($page)->toContain('<code>src/&lt;b&gt;x&lt;/b&gt;.php:3</code>')
        ->and($page)->not->toContain('<b>x</b>')
        ->and($page)->toContain('<span class="mutator" title="Pl&quot;u&apos;s">')
        ->and($page)->not->toContain('<script>alert(1)')
        ->and(substr_count($page, '</script>'))->toBe(2)
        ->and(Decoded::at(pageData($page), 'files', 0, 'mutants', 0, 'diff'))->toContain('</script><script>alert(1)</script>');
});

it('inlines its style and its script, so it loads nothing from a network, neither able to end its element', function (): void {
    $page = ReportPage::html(JsonReport::of(Verdicts::passing()), 'a::after { content: "</style>"; }', 'const end = "</script>";');

    expect($page)->toContain('<style>a::after { content: "<\/style>"; }</style>')
        ->and($page)->toContain('<script>const end = "<\/script>";</script>')
        ->and($page)->not->toMatch('~(src|href)="https?://~');
});

it('names the first 25 survivors and the 8 files with the most, the rest in the files below', function (int $survivors, int $more): void {
    $judged = [];

    foreach (range(1, $survivors) as $at) {
        $judged[] = JudgedMutant::of(
            Verdicts::mutant(sprintf('src/F%d.php:%d', $at % 10, $at), 'Plus', MutatorFamily::Arithmetic, Verdicts::diff(sprintf('$a + %d', $at), sprintf('$a - %d', $at))),
            MutantJudgement::Survived,
        );
    }

    $page = pageOf(Verdicts::of(Floor::of(80), ...$judged));

    expect(substr_count($page, '<li><a href="#m-'))->toBe(25)
        ->and(substr_count($page, '<li><a href="#f-'))->toBe(8)
        ->and($page)->toContain(sprintf('<h2 id="survivors-title">Survivors <span class="count">%d</span></h2>', $survivors))
        ->and(substr_count($page, sprintf('<p class="aside">And %d more, each in its file below.</p>', $more)))->toBe($more)
        ->and(substr_count($page, 'more, each in its file below.'))->toBe($more);
})->with([
    'exactly as many as it names' => [25, 0],
    'one more' => [26, 1],
]);
