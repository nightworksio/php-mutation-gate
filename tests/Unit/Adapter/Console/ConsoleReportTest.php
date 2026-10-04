<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Console\ConsoleReport;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Killings;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\Console\Output\BufferedOutput;

$printed = static function (Verdict $verdict): string {
    $output = new BufferedOutput();
    $answer = ConsoleReport::to($output)->report($verdict);

    expect($answer)->toEqual(Written::to('the console'));

    return $output->fetch();
};

$indented = static fn(string $block): string => implode("\n", array_map(
    static fn(string $line): string => $line === '' ? '' : sprintf('  %s', $line),
    explode("\n", $block),
));

it('prints the verdict, the trees, new code, units, reach, what was not killed, ignores, equivalents, failures and warnings', function () use ($printed, $indented): void {
    $blocks = [];

    foreach (Verdicts::failing()->trees() as $tree) {
        foreach ($tree->survivors() as $mutant) {
            $blocks[] = $indented(MutantText::block($mutant, TestNames::none()));
        }
    }

    $ignored = iterator_to_array(Verdicts::everyJudgement(), preserve_keys: false);

    expect($printed(Verdicts::failing()))->toBe(implode("\n", [
        'mutation-gate: failed',
        'The project scores 44.44%.',
        '',
        'Trees',
        '  src scores 44.44%, below its floor of 80.00%. That is -2.50 against the base.',
        '  app/Legacy is exempt: Replaced by the new billing module',
        '  src/Empty has nothing to mutate.',
        '',
        'New code',
        '  New code scores 0.00%, below its floor of 100.00%.',
        '',
        'Units',
        '  3: 1 run, 1 proved, 1 carried.',
        '',
        'Reach',
        '  src/Money.php changed, so it is reached.',
        '',
        'Not killed (5)',
        implode("\n\n", $blocks),
        '',
        'Ignored',
        sprintf('  %s: Logging is asserted in the integration suite', MutantText::heading($ignored[7])),
        sprintf('  %s', MutantText::heading($ignored[8])),
        '',
        'Equivalent, proven',
        sprintf('  %s', MutantText::heading($ignored[10])),
        '',
        'Failures',
        '  The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.',
        '',
        'Warnings',
        '  src/Kernel.php is run by 412 of 430 tests and nothing holds it.',
        '',
    ]));
});

it('says a run was cut short, and which floors can rise', function () use ($printed): void {
    expect($printed(Verdicts::passing()->cutShort()))->toBe(implode("\n", [
        'mutation-gate: passed',
        'The run\'s budget stopped it before every mutant was judged.',
        'The project scores 100.00%.',
        '',
        'Trees',
        '  src scores 100.00% against its floor of 80.00%.',
        '',
        'Units',
        '  1: 1 run, 0 proved, 0 carried.',
        '',
        'Floors that can rise',
        '  src to 100.00%',
        '  Raise them with vendor/bin/mutation-gate baseline --write, and commit the baseline.',
        '',
    ]));
});

it('says the run cannot judge, and why, before its trees', function () use ($printed): void {
    expect($printed(Verdicts::named('cannot judge')))->toStartWith(implode("\n", [
        'mutation-gate: cannot-judge',
        'The project scores 44.44%.',
        '',
        'Cannot judge',
        sprintf('  %s', Verdicts::UNJUDGED),
        '',
        'Trees',
    ]));
});

it('never prints nothing to mutate as a percentage', function () use ($printed): void {
    $empty = $printed(Verdicts::empty());

    expect($empty)->toBe(implode("\n", [
        'mutation-gate: passed',
        'The project has nothing to mutate.',
        '',
        'Trees',
        '  src has nothing to mutate.',
        '',
    ]))
        ->and($empty)->not->toContain('100');
});

it('prints what the project wrote as it is, reading no markup in it', function () use ($printed): void {
    expect($printed(Verdicts::failing()))->toContain('if ($amount <= $limit) {');
});

it('writes to the console', function (): void {
    expect(ConsoleReport::fromOptions(Options::none()))->toBeInstanceOf(ConsoleReport::class);
});

it('ends with what the run took and saved, for a timed run', function () use ($printed): void {
    expect($printed(Verdicts::named('accounted')))->toEndWith(sprintf(
        "  src/Kernel.php is run by 412 of 430 tests and nothing holds it.\n\n%s\n",
        SavingsText::of(Verdicts::named('accounted'), NoHistory::yet()),
    ));
});

it('names the judging tests as the runner named them', function () use ($printed): void {
    expect($printed(Verdicts::named('with a matrix')))->toContain('Judged by: tests/Unit/MoneyTest.php::it fits, ');
});

it('prints each cluster once, in the place of its first member, among what was not killed', function () use ($printed, $indented): void {
    $verdict = Clustered::verdict();
    [$expression, $gap] = iterator_to_array($verdict->trees()->clusters(), preserve_keys: false);

    expect($printed($verdict))->toContain(implode("\n", [
        'Not killed (7)',
        $indented(implode("\n\n", [
            ClusterText::block($expression),
            ClusterText::block($gap),
            MutantText::block(Clustered::listed($verdict->trees()->mutants())[3], TestNames::none()),
        ])),
    ]));
});

it('prints each package\'s security set after new code, and the security floors that can rise', function () use ($printed): void {
    $said = $printed(Verdicts::secured());

    expect($said)->toContain(implode("\n", [
        'New code',
        '  New code scores 0.00%, below its floor of 100.00%.',
        '',
        'Security',
        '  Security scores 0.00%, below its floor of 100.00%.',
        '  Security in packages/billing scores 100.00% against its floor of 50.00%.',
        '',
        'Units',
    ]))
        ->and($said)->toContain(implode("\n", [
            'Floors that can rise',
            '  security set of packages/billing to 100.00%',
            '  Raise them with vendor/bin/mutation-gate baseline --write, and commit the baseline.',
        ]));
});

it('prints what each suite alone kills after security, and why each is a lower bound', function () use ($printed): void {
    expect($printed(Killings::suited(MatrixKind::FirstKiller)))->toContain(implode("\n", [
        '',
        'Suites',
        '  unit alone kills at least 66.66% of the 3 mutants its tests cover.',
        '  feature alone kills at least 50.00% of the 2 mutants its tests cover.',
        '  Each is a lower bound, from first killers: `mutation-gate run --kill-matrix=full` makes it exact.',
        '',
        'Units',
    ]))
        ->and($printed(Verdicts::failing()))->not->toContain('Suites');
});

it('prints a hostile suite\'s name as one plain line, with no control character', function () use ($printed): void {
    $said = $printed(Killings::hostile());

    expect($said)->toContain("\n  <script>alert(1)</script>|x [31mred alone kills 66.66% of the 3 mutants its tests cover.\n")
        ->and($said)->not->toContain("\e");
});
