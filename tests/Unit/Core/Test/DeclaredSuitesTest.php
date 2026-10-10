<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\Suites;

$declared = static fn(string ...$names): DeclaredSuites => DeclaredSuites::of(
    ...array_map(static fn(string $name): DeclaredSuite => DeclaredSuite::named($name, Paths::none(), Paths::none()), $names),
);

it('keeps the suites the lists name where it declares each', function () use ($declared): void {
    $suites = $declared('Unit', 'Contract', 'Process');

    expect($suites->listing(JudgingSuites::every()))->toEqual(JudgingSuites::every())
        ->and($suites->listing(JudgingSuites::judging(Suites::listed('Unit'))))->toEqual(JudgingSuites::judging(Suites::listed('Unit')))
        ->and($suites->listing(JudgingSuites::holding(Suites::listed('Unit'), Suites::listed('Process'))))
        ->toEqual(JudgingSuites::holding(Suites::listed('Unit'), Suites::listed('Process')));
});

it('has every declared suite the holding list leaves out judge every unit, where no suite is listed to', function () use ($declared): void {
    expect($declared('Unit', 'Process', 'Contract')->listing(JudgingSuites::holding(Suites::all(), Suites::listed('Process'))))
        ->toEqual(JudgingSuites::holding(Suites::listed('Unit', 'Contract'), Suites::listed('Process')));
});

it('refuses a name either list gives that it does not declare, naming those it does', function () use ($declared): void {
    expect($declared('Unit', 'Process')->listing(JudgingSuites::judging(Suites::listed('Unit', "e2e\e[31m"))))
        ->toEqual(CannotJudge::because('tests.suites lists e2e[31m, which names no test suite. The PHPUnit config declares: Unit, Process.'))
        ->and($declared('Unit', 'Process')->listing(JudgingSuites::holding(Suites::listed('e2e'), Suites::listed('Process'))))
        ->toEqual(CannotJudge::because('tests.suites lists e2e, which names no test suite. The PHPUnit config declares: Unit, Process.'))
        ->and($declared('Unit', 'Process')->listing(JudgingSuites::holding(Suites::listed('Unit'), Suites::listed('Proc'))))
        ->toEqual(CannotJudge::because('tests.holding lists Proc, which names no test suite. The PHPUnit config declares: Unit, Process.'))
        ->and($declared()->listing(JudgingSuites::holding(Suites::all(), Suites::listed('Process'))))
        ->toEqual(CannotJudge::because('tests.holding lists Process, which names no test suite: the PHPUnit config declares none by name.'));
});

it('refuses holding every suite it declares, which leaves no test to judge a unit nothing holds', function () use ($declared): void {
    expect($declared('Unit', 'Process')->listing(JudgingSuites::holding(Suites::all(), Suites::listed('Process', 'Unit'))))
        ->toEqual(CannotJudge::because(<<<'SAID'
            tests.holding lists every suite the PHPUnit config declares, so no test judges a unit nothing holds.
            List the suites whose tests judge every unit in tests.suites, or hold fewer.
            SAID));
});
