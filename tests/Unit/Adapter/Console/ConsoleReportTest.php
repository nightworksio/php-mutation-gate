<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Console\ConsoleReport;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
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

it('prints the verdict, the trees, new code, units, reach, what was not killed, ignores, failures and warnings', function () use ($printed, $indented): void {
    $blocks = [];

    foreach (Verdicts::failing()->trees() as $tree) {
        foreach ($tree->survivors() as $mutant) {
            $blocks[] = $indented(MutantText::block($mutant));
        }
    }

    $ignored = iterator_to_array(Verdicts::everyJudgement(), preserve_keys: false);

    expect($printed(Verdicts::failing()))->toBe(implode("\n", [
        'mutation-gate: failed',
        'The project scores 37.50%.',
        '',
        'Trees',
        '  src scores 37.50%, below its floor of 80.00%. That is -2.50 against the base.',
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
