<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\JUnit;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use NightWorksIO\MutationGate\Tests\Support\Xpath;

$failing = static fn(string $query): array => Xpath::of(JUnit::xml(Verdicts::failing()), $query);

it('writes a suite per tree, one for new code, one for the run and one for the ignored mutants, each failing as the gate does', function () use ($failing): void {
    expect($failing('/testsuites/@name'))->toBe(['mutation-gate'])
        ->and($failing('/testsuites/@tests'))->toBe(['7'])
        ->and($failing('/testsuites/@failures'))->toBe(['3'])
        ->and($failing('/testsuites/testsuite/@name'))->toBe(['src', 'app/Legacy', 'src/Empty', 'new code', 'run', 'ignored'])
        ->and($failing('/testsuites/testsuite/@tests'))->toBe(['1', '1', '1', '1', '1', '2'])
        ->and($failing('/testsuites/testsuite/@failures'))->toBe(['1', '0', '0', '1', '1', '0']);
});

it('skips a case for each mutant an ignore left out, named by its heading, with why it is ignored', function () use ($failing): void {
    expect($failing('/testsuites/testsuite[6]/testcase/@name'))->toBe([
        'src/Log.php:4  MethodCallRemoval  ignored  1fbb0cb71a10',
        'src/Log.php:6  Concat  ignored by a native marker  33b077f96af4',
    ])
        ->and($failing('/testsuites/testsuite[6]/testcase/@classname'))->toBe(['ignored', 'ignored'])
        ->and($failing('/testsuites/testsuite[6]/testcase/skipped/@message'))->toBe([
            'Logging is asserted in the integration suite',
            'ignored by a native marker',
        ])
        ->and($failing('/testsuites/testsuite[6]/testcase/failure'))->toBe([]);
});

it('fails a tree\'s floor with every mutant it counts as not killed', function () use ($failing): void {
    $said = 'src scores 44.44%, below its floor of 80.00%. That is -2.50 against the base.';
    $blocks = [];

    foreach (Verdicts::failing()->trees() as $tree) {
        foreach ($tree->survivors() as $mutant) {
            $blocks[] = MutantText::block($mutant, TestNames::none());
        }
    }

    expect($failing('/testsuites/testsuite[1]/testcase/@name'))->toBe(['floor'])
        ->and($failing('/testsuites/testsuite[1]/testcase/@classname'))->toBe(['src'])
        ->and($failing('/testsuites/testsuite[1]/testcase/failure/@type'))->toBe(['floor'])
        ->and($failing('/testsuites/testsuite[1]/testcase/failure/@message'))->toBe([$said])
        ->and($failing('/testsuites/testsuite[1]/testcase/failure'))->toBe([implode("\n\n", [$said, ...$blocks])]);
});

it('skips an exempt tree with its reason, and says what a passing one scored', function () use ($failing): void {
    expect($failing('/testsuites/testsuite[2]/testcase/skipped/@message'))->toBe(['app/Legacy is exempt: Replaced by the new billing module'])
        ->and($failing('/testsuites/testsuite[3]/testcase/system-out'))->toBe(['src/Empty has nothing to mutate.'])
        ->and($failing('/testsuites/testsuite[3]/testcase/failure'))->toBe([]);
});

it('names the new-code case by its package, and the run\'s case by its failure', function () use ($failing): void {
    $failure = 'The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.';

    expect($failing('/testsuites/testsuite[4]/testcase/@name'))->toBe(['.'])
        ->and($failing('/testsuites/testsuite[4]/testcase/@classname'))->toBe(['new code'])
        ->and($failing('/testsuites/testsuite[4]/testcase/failure/@message'))->toBe(['New code scores 0.00%, below its floor of 100.00%.'])
        ->and($failing('/testsuites/testsuite[5]/testcase/@name'))->toBe([$failure])
        ->and($failing('/testsuites/testsuite[5]/testcase/@classname'))->toBe(['run'])
        ->and($failing('/testsuites/testsuite[5]/testcase/failure/@type'))->toBe(['run'])
        ->and($failing('/testsuites/testsuite[5]/testcase/failure'))->toBe([$failure]);
});

it('passes a tree that meets its floor, and writes no new-code or run suite where there is none', function (): void {
    expect(JUnit::xml(Verdicts::passing()))->toBe(implode("\n", [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<testsuites name="mutation-gate" tests="1" failures="0">',
        '  <testsuite name="src" tests="1" failures="0">',
        '    <testcase name="floor" classname="src"><system-out>src scores 100.00% against its floor of 80.00%.</system-out></testcase>',
        '  </testsuite>',
        '</testsuites>',
        '',
    ]));
});

it('escapes what the project wrote', function () use ($failing): void {
    expect(JUnit::xml(Verdicts::failing()))->toContain('if ($amount &lt; $limit) {')
        ->and($failing('/testsuites'))->toHaveCount(1);
});

it('fails a case of the run for each thing that kept it from judging, before its failures', function (): void {
    $xml = JUnit::xml(Verdicts::named('cannot judge'));

    expect(Xpath::of($xml, '/testsuites/@failures'))->toBe(['4'])
        ->and(Xpath::of($xml, '/testsuites/testsuite[5]/testcase/@name'))->toBe([
            Verdicts::UNJUDGED,
            'The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.',
        ])
        ->and(Xpath::of($xml, '/testsuites/testsuite[5]/testcase/failure/@type'))->toBe(['cannot-judge', 'run']);
});

it('names the judging tests of a failed floor\'s mutants as the runner named them', function (): void {
    expect(implode("\n", Xpath::of(JUnit::xml(Verdicts::named('with a matrix')), '/testsuites/testsuite[1]/testcase/failure')))
        ->toContain('Judged by: tests/Unit/MoneyTest.php::it fits, ');
});

it('writes a security suite with the floor case of each package\'s set, failing as the gate does', function (): void {
    $secured = static fn(string $query): array => Xpath::of(JUnit::xml(Verdicts::secured()), $query);

    expect($secured('/testsuites/testsuite/@name'))
        ->toBe(['src', 'app/Legacy', 'src/Empty', 'src/Auth.php', 'packages/billing/src', 'new code', 'security', 'run', 'ignored'])
        ->and($secured('/testsuites/testsuite[7]/@tests'))->toBe(['2'])
        ->and($secured('/testsuites/testsuite[7]/@failures'))->toBe(['1'])
        ->and($secured('/testsuites/testsuite[7]/testcase/@name'))->toBe(['floor', 'floor'])
        ->and($secured('/testsuites/testsuite[7]/testcase/@classname'))->toBe(['.', 'packages/billing'])
        ->and($secured('/testsuites/testsuite[7]/testcase[1]/failure/@message'))
        ->toBe(['Security scores 0.00%, below its floor of 100.00%.']);
});
