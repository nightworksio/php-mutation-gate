<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Page\ReportPage;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Provenance;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
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

    expect($page)->toContain('<span class="status status--failed">Failed</span>')
        ->and($page)->toContain(sprintf('<li>%s</li>', htmlspecialchars($report['trees'][0]['said'])))
        ->and($page)->toContain(sprintf('<li>%s</li>', htmlspecialchars($report['newCode'][0]['said'])))
        ->and($page)->toContain(sprintf('<li>%s</li>', htmlspecialchars($report['failures'][0], ENT_QUOTES | ENT_HTML5)))
        ->and($page)->toContain('<li>The project scores 44.44%.</li>')
        ->and(pageOf(Verdicts::passing()))->toContain('<span class="status status--passed">Passed</span>')
        ->and(pageOf(Verdicts::passing()))->toContain('<ul class="why"><li>The project scores 100.00%.</li></ul>');
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
        ->and($page)->toContain('<p class="aside">12 judged, 3 left out of the score.</p>');
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

it('folds away what the run warns of, why the change reached what it did and what failed it, or says it has nothing more', function (): void {
    $page = pageOf(Verdicts::failing());

    expect($page)->toContain('<section><h3>Failures</h3>')
        ->and($page)->toContain('<section><h3>Reach</h3>')
        ->and(pageOf(Verdicts::empty()))->toContain('<p class="empty">The run left nothing more to say.</p>');
});

it('escapes everything a project writes, in the page and in its data', function (): void {
    $hostile = JudgedMutant::of(
        Verdicts::mutant('src/<b>x</b>.php:3', 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('</script><script>alert(1)</script>', '& "x"')),
        MutantJudgement::Survived,
    );
    $page = pageOf(Verdicts::of(Floor::of(80), $hostile));

    expect($page)->toContain('<code>src/&lt;b&gt;x&lt;/b&gt;.php:3</code>')
        ->and($page)->not->toContain('<b>x</b>')
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
