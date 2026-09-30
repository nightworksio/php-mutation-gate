<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Annotations;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

$printed = static function (Verdict $verdict): array {
    $file = sprintf('%s/annotations', Scratch::directory());

    expect(Annotations::printingTo($file)->report($verdict))->toEqual(Written::to($file));

    return is_file($file) ? explode("\n", rtrim((string) file_get_contents($file), "\n")) : [];
};

it('annotates a survivor in a set that failed as an error, where it is, with its hint and command', function () use ($printed): void {
    $survivor = Verdicts::survivor();

    expect($printed(Verdicts::of(Floor::of(80), $survivor)))->toBe([sprintf(
        '::error file=src/Money.php,line=7,endLine=7,title=Mutant survived%%3A LessToLessOrEqual::%s Reproduce: vendor/bin/mutation-gate reproduce %s',
        $survivor->hint()->text(),
        $survivor->mutant()->id()->value(),
    )]);
});

it('warns of one in a set that passed, and gives every warning of the verdict as a notice', function () use ($printed): void {
    $verdict = Verdicts::of(Floor::of(0), Verdicts::survivor())
        ->withWarnings(Warnings::of(Warning::that('src/Kernel.php is run by 412 of 430 tests: nothing holds it.')));
    $lines = $printed($verdict);

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toStartWith('::warning file=src/Money.php,line=7,')
        ->and($lines[1])->toBe('::notice title=mutation-gate::src/Kernel.php is run by 412 of 430 tests: nothing holds it.');
});

it('annotates every mutant counted as not killed, and nothing else', function () use ($printed): void {
    $titles = array_map(
        static fn(string $line): string => (string) preg_replace('/^::(\w+) .*title=([^:]*)::.*$/', '$1 $2', $line),
        $printed(Verdicts::failing()),
    );

    expect($titles)->toBe([
        'error Mutant survived%3A LessToLessOrEqual',
        'error Mutant uncovered%3A FalseValue',
        'error Mutant flaky%3A MethodCallRemoval',
        'error Mutant unjudged%3A DecrementInteger',
        'error Mutant too slow to judge%3A Plus',
        'notice mutation-gate',
    ]);
});

it('ranks changed lines first, then sets that failed, and keeps 10 of each level', function () use ($printed): void {
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Changed.php'), Lines::of(...array_map(Line::of(...), range(1, 12))));
    $mutants = [];

    foreach (range(1, 12) as $line) {
        $mutants[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/Unchanged.php:%d', $line), 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived);
    }

    foreach (range(1, 12) as $line) {
        $mutants[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/Changed.php:%d', $line), 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived)->within($reach);
    }

    $warnings = Warnings::of(...array_map(static fn(int $at): Warning => Warning::that(sprintf('warning %d', $at)), range(1, 12)));
    $lines = $printed(Verdicts::of(Floor::of(80), ...$mutants)->withWarnings($warnings));
    $files = array_map(static fn(string $line): string => (string) preg_replace('/^::\w+ file=([^,]*),line=(\d+),.*$/', '$1:$2', $line), array_slice($lines, 0, 10));

    expect($lines)->toHaveCount(20)
        ->and($files)->toBe(array_map(static fn(int $line): string => sprintf('src/Changed.php:%d', $line), range(1, 10)))
        ->and($lines[10])->toBe('::notice title=mutation-gate::warning 1')
        ->and($lines[19])->toBe('::notice title=mutation-gate::warning 10');
});

it('keeps 10 warnings, and writes nothing for a verdict with no survivor', function () use ($printed): void {
    $mutants = [];

    foreach (range(1, 12) as $line) {
        $mutants[] = JudgedMutant::of(Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, ''), MutantJudgement::Survived);
    }

    expect($printed(Verdicts::of(Floor::of(0), ...$mutants)))->toHaveCount(10)
        ->and($printed(Verdicts::passing()))->toBe([]);
});

it('prints to the step\'s output', function (): void {
    expect(Annotations::fromOptions(Options::none()))->toEqual(Annotations::printingTo('php://stdout'));
});

it('annotates a cluster once, at its first member, with why one test may kill them all and the stub command', function () use ($printed): void {
    $verdict = Clustered::verdict();
    [$expression, $gap] = iterator_to_array($verdict->trees()->clusters(), preserve_keys: false);
    $lone = Clustered::lone();
    $lines = $printed($verdict);

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toBe(sprintf(
            '::error file=src/Cart.php,line=7,endLine=7,title=Mutant cluster%%3A 3 survivors%%2C one expression::%s Stub: vendor/bin/mutation-gate stub %s',
            ClusterText::hint($expression),
            $expression->id()->value(),
        ))
        ->and($lines[1])->toStartWith('::error file=src/Cart.php,line=16,endLine=16,title=Mutant cluster%3A 3 survivors%2C one gap::')
        ->and($lines[1])->toEndWith(sprintf('Stub: vendor/bin/mutation-gate stub %s', $gap->id()->value()))
        ->and($lines[2])->toStartWith('::error file=src/Cart.php,line=11,')
        ->and($lines[2])->toEndWith(sprintf('Reproduce: vendor/bin/mutation-gate reproduce %s', $lone->mutant()->id()->value()));
});

it('ranks a cluster by any member on a changed line, and warns of one in a set that passed', function () use ($printed): void {
    $verdict = Clustered::verdict();
    $passing = Verdict::of(TreeVerdicts::of(TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(0), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        $verdict->trees()->mutants(),
        Uncovered::Count,
    )));

    expect(array_map(static fn(string $line): string => explode(' ', $line)[0], $printed($passing)))
        ->toBe(['::warning', '::warning', '::warning'])
        ->and($printed($passing)[2])->toStartWith('::warning file=src/Cart.php,line=11,');
});

it('writes a warning that holds a workflow command as one notice, encoded, which starts no other', function () use ($printed): void {
    $verdict = Verdicts::of(Floor::of(0))
        ->withWarnings(Warnings::of(Warning::that("phpstan said: return.type, x:y%0A\n::error::injected\r\n")));

    expect($printed($verdict))->toBe(['::notice title=mutation-gate::phpstan said: return.type, x:y%250A%0A::error::injected%0D%0A']);
});
