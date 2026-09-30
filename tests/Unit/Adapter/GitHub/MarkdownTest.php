<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Markdown;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$run = 'https://github.com/octo/gate/actions/runs/7';

it('writes the sticky comment: the verdict, trees, new code, survivors on changed lines, unjudged and flaky, failures, warnings and the run', function () use ($run): void {
    $survivor = Verdicts::survivor();
    $id = static fn(int $at): string => iterator_to_array(Verdicts::everyJudgement(), preserve_keys: false)[$at]->mutant()->id()->value();

    expect(Markdown::comment(Verdicts::failing(), $run))->toBe(implode("\n\n", [
        '<!-- mutation-gate -->',
        '## mutation-gate: failed',
        'The project scores 37.50%.',
        implode("\n", [
            '| Tree | Floor | Score | Against the base | Result |',
            '|---|---|---|---|---|',
            '| <code>src</code> | 80.00% | 37.50% | -2.50 | failed |',
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
            '| Mutant | Mutator | Judgement | What the tests miss | Reproduce |',
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
    $summary = Markdown::summary(Verdicts::failing(), $run);

    expect($summary)->toStartWith("## mutation-gate: failed\n\nThe project scores 37.50%.")
        ->and($summary)->not->toContain(Markdown::MARKER)
        ->and($summary)->toContain("### Not killed (5)\n\n| Mutant | Mutator | Judgement | What the tests miss | Reproduce |")
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

    $summary = Markdown::summary(Verdicts::of(Floor::of(80), ...$mutants), '');

    expect(strlen($summary))->toBeLessThanOrEqual(Markdown::SUMMARY_BYTES)
        ->and($summary)->toContain('### Not killed (300)')
        ->and($summary)->toMatch('/And \d+ more; the JSON report lists every one\./');
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
