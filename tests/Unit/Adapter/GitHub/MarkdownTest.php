<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Markdown;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Core\Report\CostText;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$run = 'https://github.com/octo/gate/actions/runs/7';

it('writes the sticky comment: the verdict, trees, new code, survivors on changed lines, unjudged and flaky, failures, warnings and the run', function () use ($run): void {
    $survivor = Verdicts::survivor();
    $id = static fn(int $at): string => iterator_to_array(Verdicts::everyJudgement(), preserve_keys: false)[$at]->mutant()->id()->value();

    expect(Markdown::comment(Verdicts::failing(), $run))->toBe(implode("\n\n", [
        '<!-- mutation-gate -->',
        '## mutation-gate: failed',
        'The project scores 44.44%.',
        implode("\n", [
            '| Tree | Floor | Score | Against the base | Result |',
            '|---|---|---|---|---|',
            '| <code>src</code> | 80.00% | 44.44% | -2.50 | failed |',
            '| <code>app/Legacy</code> | exempt |  |  | exempt: Replaced by the new billing module |',
            '| <code>src/Empty</code> | 90.00% | nothing to mutate |  | nothing-to-mutate |',
        ]),
        '- New code scores 0.00%, below its floor of 100.00%.',
        '### Survivors on changed lines (1)',
        implode("\n\n", [
            '<details><summary><code>src/Money.php:7</code> LessToLessOrEqual, survived</summary>',
            "~~~diff\n--- Original\n+++ New\n@@ @@\n-        if (\$amount < \$limit) {\n+        if (\$amount <= \$limit) {\n~~~",
            'No test uses a value at the boundary of <code>$amount &lt; $limit</code>. It is judged by <code>MoneyTest::fits</code>, <code>MoneyTest::refuses</code>, <code>PriceTest::adds</code> and 1 more.',
            sprintf('<code>vendor/bin/mutation-gate reproduce %s</code>', $survivor->mutant()->id()->value()),
            '</details>',
        ]),
        '### Unjudged and flaky (2)',
        implode("\n", [
            '| Mutant | Mutator | Judgement | What the tests miss | Command |',
            '|---|---|---|---|---|',
            sprintf('| <code>src/Order.php:3</code> | MethodCallRemoval | flaky | Its tests killed it on one run and let it survive on another, so they are the suspects. It is judged by <code>OrderTest::saves</code>. | <code>vendor/bin/mutation-gate reproduce %s</code> |', $id(3)),
            sprintf('| <code>src/Order.php:5</code> | DecrementInteger | unjudged | The run&apos;s budget ran out before it. Nothing judged it before the run stopped, so it counts as not killed. | <code>vendor/bin/mutation-gate reproduce %s</code> |', $id(4)),
        ]),
        '### Failures',
        '- The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.',
        '### Warnings',
        '- src/Kernel.php is run by 412 of 430 tests and nothing holds it.',
        sprintf("[The run](%s) keeps the HTML report among its artifacts.\n", $run),
    ]));
});

it('says the run cannot judge, and why, before its failures', function (): void {
    $comment = Markdown::comment(Verdicts::named('cannot judge'), '');

    expect($comment)->toStartWith("<!-- mutation-gate -->\n\n## mutation-gate: cannot-judge\n")
        ->and($comment)->toContain(sprintf(
            "### Cannot judge\n\n- %s\n\n### Failures\n\n- The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.",
            Verdicts::UNJUDGED,
        ));
});

it('writes the comment of a passing run, with the floors that can rise and a run cut short', function (): void {
    expect(Markdown::comment(Verdicts::passing()->cutShort(), ''))->toBe(implode("\n\n", [
        '<!-- mutation-gate -->',
        '## mutation-gate: passed',
        'The project scores 100.00%. The run\'s budget stopped it before every mutant was judged.',
        "| Tree | Floor | Score | Against the base | Result |\n|---|---|---|---|---|\n| <code>src</code> | 80.00% | 100.00% |  | passed |",
        '### Floors that can rise',
        '- <code>src</code> to 100.00%',
        "Raise them with `vendor/bin/mutation-gate baseline --write`, and commit the baseline.\n",
    ]));
});

it('comments on up to 20 survivors on changed lines, and 20 unjudged or flaky, then says how many more', function (): void {
    $lines = Lines::of(...array_map(Line::of(...), range(1, 25)));
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Money.php'), $lines);
    $mutants = [];

    foreach (range(1, 25) as $line) {
        $mutants[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived)->within($reach);
        $mutants[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/Order.php:%d', $line), 'Plus', MutatorFamily::None, ''), MutantJudgement::Flaky);
        $mutants[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/Price.php:%d', $line), 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived);
    }

    $comment = Markdown::comment(Verdicts::of(Floor::of(80), ...$mutants), '');

    expect(substr_count($comment, '<details>'))->toBe(20)
        ->and(substr_count($comment, '| <code>src/Order.php:'))->toBe(20)
        ->and($comment)->not->toContain('src/Price.php')
        ->and($comment)->toContain('### Survivors on changed lines (25)')
        ->and($comment)->toContain('### Unjudged and flaky (25)')
        ->and(substr_count($comment, 'And 5 more; the JSON report lists every one.'))->toBe(2);
});

it('writes the step summary with every mutant counted as not killed in one table', function () use ($run): void {
    $summary = Markdown::summary(Verdicts::failing(), $run, Verdicts::monthAgo());

    expect($summary)->toStartWith("## mutation-gate: failed\n\nThe project scores 44.44%.")
        ->and($summary)->not->toContain(Markdown::MARKER)
        ->and($summary)->toContain("### Not killed (5)\n\n| Mutant | Mutator | Judgement | What the tests miss | Command |")
        ->and(substr_count($summary, '| <code>vendor/bin/mutation-gate reproduce '))->toBe(5)
        ->and($summary)->not->toContain('<details>')
        ->and($summary)->toEndWith(sprintf("[The run](%s) keeps the HTML report among its artifacts.\n", $run));
});

it('fits the step summary into the size one step may write, saying how many it left out', function (): void {
    $tests = TestIds::of(...array_map(static fn(int $at): TestId => TestId::of(sprintf('%s%d', str_repeat('LongTestName', 400), $at)), range(1, 3)));
    $mutants = [];

    foreach (range(1, 300) as $line) {
        $mutants[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived)->judgedBy($tests);
    }

    $summary = Markdown::summary(Verdicts::of(Floor::of(80), ...$mutants), '', Verdicts::monthAgo());

    expect(strlen($summary))->toBeLessThanOrEqual(Markdown::SUMMARY_BYTES)
        ->and($summary)->toContain('### Not killed (300)')
        ->and($summary)->toMatch('/And \d+ more; the JSON report lists every one\./');
});

it('keeps a step summary of exactly the mebibyte one step may write whole, and cuts one a byte past it', function (): void {
    $judged = static fn(int $line, int $length): JudgedMutant => JudgedMutant::of(
        Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, ''),
        MutantJudgement::Survived,
    )->judgedBy(TestIds::of(TestId::of(str_repeat('a', $length))));
    $summary = static fn(int $length): string => Markdown::summary(
        Verdicts::of(Floor::of(80), $judged(3, 1), $judged(4, $length)),
        '',
        Verdicts::monthAgo(),
    );
    $fits = 1_048_576 - (strlen($summary(2)) - 2);

    expect(strlen($summary($fits)))->toBe(1_048_576)
        ->and($summary($fits))->toContain(str_repeat('a', $fits))
        ->and($summary($fits + 1))->not->toContain(str_repeat('a', $fits + 1))
        ->and($summary($fits + 1))->toContain('And 1 more; the JSON report lists every one.');
});

it('fits a step summary whose one survivor alone is past the size one step may write, by showing none', function (): void {
    $huge = JudgedMutant::of(Verdicts::mutant('src/Money.php:3', 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived)
        ->judgedBy(TestIds::of(TestId::of(str_repeat('a', 1_100_000))));
    $summary = Markdown::summary(Verdicts::of(Floor::of(80), $huge), '', Verdicts::monthAgo());

    expect(strlen($summary))->toBeLessThanOrEqual(1_048_576)
        ->and($summary)->toContain('### Not killed (1)')
        ->and($summary)->toContain('And 1 more; the JSON report lists every one.');
});

it('stops cutting once it shows no survivor, even where what is left is past the size one step may write', function (): void {
    $path = sprintf('src/%s', str_repeat('a', 1_100_000));
    $tree = TreeVerdict::judged(
        Tree::at(Path::of($path), Floor::of(80), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::of(JudgedMutant::of(Verdicts::mutant(sprintf('%s/Money.php:3', $path), 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived)),
        Uncovered::Count,
    );
    $summary = Markdown::summary(Verdict::of(TreeVerdicts::of($tree)), '', Verdicts::monthAgo());

    expect($summary)->toContain('And 1 more; the JSON report lists every one.')
        ->and($summary)->not->toContain('| Mutant | Mutator |');
});

it('lets nothing the project wrote become markup, a link, a mention or a new cell', function (): void {
    $hostile = JudgedMutant::of(
        Verdicts::mutant('src/<img src=x>|@octocat.php:3', '[x](https://evil)', MutatorFamily::None, "@@ @@\n-a ~~~ </details>\n+b\n"),
        MutantJudgement::Flaky,
    )->judgedBy(TestIds::of(TestId::of('it @octocat </code><script>')));
    $comment = Markdown::comment(Verdicts::of(Floor::of(80), $hostile), '');

    expect($comment)->not->toContain('<img')
        ->and($comment)->not->toContain('<script')
        ->and($comment)->not->toContain('@octocat')
        ->and($comment)->not->toContain('[x]')
        ->and($comment)->toContain('src/&lt;img src=x&gt;&#124;&#64;octocat.php:3')
        ->and(array_values(array_filter(explode("\n", $comment), static fn(string $line): bool => str_contains($line, 'octocat.php'))))
        ->toBe([sprintf(
            '| <code>src/&lt;img src=x&gt;&#124;&#64;octocat.php:3</code> | &#91;x&#93;(https://evil) | flaky | %s | <code>%s</code> |',
            'Its tests killed it on one run and let it survive on another, so they are the suspects. It is judged by <code>it &#64;octocat &lt;/code&gt;&lt;script&gt;</code>.',
            $hostile->reproduce(),
        )]);
});

it('says what the run took and saved under the verdict, and folds what it cost into the comment\'s end', function () use ($run): void {
    $comment = Markdown::comment(Verdicts::named('accounted'), $run);

    expect($comment)->toStartWith(sprintf(
        "%s\n\n## mutation-gate: failed\n\n%s\n\nThe project scores 44.44%%.",
        Markdown::MARKER,
        SavingsText::of(Verdicts::named('accounted'), NoHistory::yet()),
    ))
        ->and($comment)->toEndWith(sprintf("\n\n%s\n", CostText::of(Verdicts::named('accounted'))))
        ->and(Markdown::comment(Verdicts::failing(), $run))->not->toContain('What this run cost');
});

it('adds to the step summary what the default branch saved lately', function () use ($run): void {
    $verdict = Verdicts::failing()->withAccount(Verdicts::account()->after(Trend::decode(
        '{"format": 1, "runs": [{"commit": "a", "time": "2026-09-10T00:00:00Z", "trees": {}, "runnerSeconds": 60, "fullRunSeconds": 3660}]}',
    )));
    $summary = Markdown::summary($verdict, $run, Verdicts::monthAgo());

    expect($summary)->toContain(sprintf(
        "%s\nIn the last 30 days the gate saved 2h 27m of runner time.\n\nThe project scores 44.44%%.",
        SavingsText::of($verdict, NoHistory::yet()),
    ))
        ->and(Markdown::summary(Verdicts::named('accounted'), $run, Verdicts::monthAgo()))->not->toContain('In the last 30 days')
        ->and(Markdown::summary(Verdicts::named('accounted'), $run, Verdicts::monthAgo()))->not->toContain('What this run cost');
});

/**
 * The clusters of the clustered verdict, its expression then its gap.
 *
 * @return list<Cluster>
 */
function commentedClusters(): array
{
    return iterator_to_array(Clustered::verdict()->trees()->clusters(), preserve_keys: false);
}

/** A cluster as the comment folds it: where it is, its size, each member's diff, one hint and its stub. */
function clusterDetails(Cluster $cluster): string
{
    $diffs = [];

    foreach ($cluster->members() as $member) {
        $diffs[] = sprintf("~~~diff\n%s\n~~~", rtrim($member->mutant()->mutation()->diff(), "\n"));
    }

    $at = $cluster->representative()->mutant()->location()->start()->number();

    return implode("\n\n", [
        sprintf('<details><summary><code>src/Cart.php:%d</code> %s</summary>', $at, ClusterText::size($cluster)),
        ...$diffs,
        Escape::text(ClusterText::hint($cluster)),
        sprintf('<code>vendor/bin/mutation-gate stub %s</code>', $cluster->id()->value()),
        '</details>',
    ]);
}

it('comments on a cluster as one item, with its members\' diffs, one hint and its stub command', function (): void {
    [$expression, $gap] = commentedClusters();

    expect(Markdown::comment(Clustered::verdict(), ''))->toContain(implode("\n\n", [
        '### Survivors on changed lines (6)',
        clusterDetails($expression),
        clusterDetails($gap),
    ]));
});

it('counts a cluster as one item toward the comment\'s 20, and the survivors in the heading one by one', function (): void {
    $lines = Lines::of(...array_map(Line::of(...), range(1, 20)));
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Price.php'), $lines);
    $mutants = Clustered::listed(Clustered::survivors()->clustered(Uncovered::Count, Clustered::sources()));

    foreach (range(1, 20) as $line) {
        $mutants[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/Price.php:%d', $line), 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived)->within($reach);
    }

    $comment = Markdown::comment(Verdicts::of(Floor::of(80), ...$mutants), '');

    expect($comment)->toContain('### Survivors on changed lines (26)')
        ->and(substr_count($comment, '<details>'))->toBe(20)
        ->and(substr_count($comment, '3 survivors, one'))->toBe(2)
        ->and($comment)->toContain('And 2 more; the JSON report lists every one.');
});

it('writes a cluster as one row of the step summary, with its size and its stub command', function (): void {
    [$expression, $gap] = commentedClusters();
    $row = static fn(Cluster $cluster): string => sprintf(
        '| <code>src/Cart.php:%d</code> | %s | survived | %s | <code>vendor/bin/mutation-gate stub %s</code> |',
        $cluster->representative()->mutant()->location()->start()->number(),
        ClusterText::size($cluster),
        Escape::text(ClusterText::hint($cluster)),
        $cluster->id()->value(),
    );
    $summary = Markdown::summary(Clustered::verdict(), '', Verdicts::monthAgo());

    expect($summary)->toContain(implode("\n", [
        '### Not killed (7)',
        '',
        '| Mutant | Mutator | Judgement | What the tests miss | Command |',
        '|---|---|---|---|---|',
        $row($expression),
        $row($gap),
    ]))
        ->and($summary)->toContain('| <code>src/Cart.php:11</code> | FalseValue | survived | ')
        ->and(substr_count($summary, '| <code>src/Cart.php:'))->toBe(3);
});
