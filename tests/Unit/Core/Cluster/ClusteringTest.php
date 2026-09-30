<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\Cluster\Clustering;
use NightWorksIO\MutationGate\Core\Cluster\ClusterKind;
use NightWorksIO\MutationGate\Core\Cluster\Membership;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily as Family;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement as Judged;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/**
 * Each clustered mutant's cluster, as `<kind> <cluster id>`, by the mutant's id.
 *
 * @param  array<string, Membership> $memberships
 * @return array<string, string>
 */
function clustersOf(array $memberships): array
{
    return array_map(
        static fn(Membership $membership): string => sprintf('%s %s', $membership->kind()->value, $membership->id()->value()),
        $memberships,
    );
}

/**
 * Every mutant of these, as a list.
 *
 * @return list<JudgedMutant>
 */
function survivorList(JudgedMutants $mutants): array
{
    return Clustered::listed($mutants);
}

/** A survivor of `src/Cart.php` at this line, with this diff and family, judged by these tests. */
function cartSurvivor(int $line, string $diff, Family $family, TestIds $tests, Judged $judgement = Judged::Survived): JudgedMutant
{
    return JudgedMutant::of(Verdicts::mutant(sprintf('src/Cart.php:%d', $line), sprintf('M%d', $line), $family, $diff), $judgement)
        ->judgedBy($tests);
}

it('puts changes that overlap in one statement in an expression, and calls of one function judged alike in a gap, in the order they begin', function (): void {
    $comparisons = survivorList(Clustered::comparisons());
    $calls = survivorList(Clustered::removedCalls());
    $idsOf = static fn(JudgedMutant ...$members): MutantIds => MutantIds::of(...array_map(static fn(JudgedMutant $member): MutantId => $member->mutant()->id(), $members));
    $expression = sprintf('expression %s', ClusterId::of($idsOf(...$comparisons))->value());
    $gap = sprintf('gap %s', ClusterId::of($idsOf(...$calls))->value());

    expect(clustersOf(Clustering::of(survivorList(Clustered::survivors()), Clustered::sources())))->toBe([
        $comparisons[2]->mutant()->id()->value() => $expression,
        $comparisons[0]->mutant()->id()->value() => $expression,
        $comparisons[1]->mutant()->id()->value() => $expression,
        $calls[0]->mutant()->id()->value() => $gap,
        $calls[1]->mutant()->id()->value() => $gap,
        $calls[2]->mutant()->id()->value() => $gap,
    ]);
});

it('puts survivors in an expression before a gap, so none is in two', function (): void {
    $memberships = Clustering::of(survivorList(Clustered::comparisons()), Clustered::sources());

    expect(array_map(static fn(Membership $membership): ClusterKind => $membership->kind(), array_values($memberships)))
        ->toBe([ClusterKind::Expression, ClusterKind::Expression, ClusterKind::Expression]);
});

it('clusters no survivor alone', function (): void {
    expect(Clustering::of([Clustered::lone()], Clustered::sources()))->toBe([])
        ->and(Clustering::of([], Clustered::sources()))->toBe([]);
});

it('leaves apart changes of one line that do not overlap and are of two families', function (): void {
    $source = "<?php\n\nfunction fits(\$a, \$b, \$c): bool\n{\n    return \$a < \$b && \$c;\n}\n";
    $survivors = [
        cartSurvivor(5, Verdicts::diff('return $a < $b && $c;', 'return $a <= $b && $c;'), Family::Boundary, Clustered::tests()),
        cartSurvivor(5, Verdicts::diff('return $a < $b && $c;', 'return $a < $b || $c;'), Family::Logical, Clustered::tests()),
    ];

    expect(Clustering::of($survivors, ByPath::none()->with(Path::of(Clustered::FILE), Contents::of($source))))->toBe([]);
});

it('keeps a change that runs past one statement out of an expression', function (): void {
    $block = "@@ @@\n-        if (\$amount < \$limit) {\n-            return true;\n-        }\n";
    $survivors = [
        cartSurvivor(7, Verdicts::diff('if ($amount < $limit) {', 'if ($amount <= $limit) {'), Family::Boundary, Clustered::tests()),
        JudgedMutant::of(Verdicts::mutant('src/Cart.php:7', 'IfRemoval', Family::Condition, $block), Judged::Survived)->judgedBy(Clustered::tests()),
    ];

    expect(Clustering::of($survivors, Clustered::sources()))->toBe([]);
});

it('keeps a gap to one function, one family, one judgement and the same tests', function (JudgedMutant $second): void {
    $first = cartSurvivor(16, Verdicts::diff('$this->log->write($order);', ''), Family::RemovedCall, Clustered::tests());

    expect(Clustering::of([$first, $second], Clustered::sources()))->toBe([]);
})->with([
    'judged by other tests' => [fn(): JudgedMutant => cartSurvivor(17, Verdicts::diff('$this->events->dispatch($order);', ''), Family::RemovedCall, TestIds::of(TestId::of('CartTest::fits')))],
    'judged by one test more' => [fn(): JudgedMutant => cartSurvivor(17, Verdicts::diff('$this->events->dispatch($order);', ''), Family::RemovedCall, Clustered::tests()->with(TestId::of('CartTest::empties')))],
    'of another family' => [fn(): JudgedMutant => cartSurvivor(17, Verdicts::diff('$this->events->dispatch($order);', ''), Family::ReturnValue, Clustered::tests())],
    'of no family' => [fn(): JudgedMutant => cartSurvivor(17, Verdicts::diff('$this->events->dispatch($order);', ''), Family::None, Clustered::tests())],
    'judged otherwise' => [fn(): JudgedMutant => cartSurvivor(17, Verdicts::diff('$this->events->dispatch($order);', ''), Family::RemovedCall, Clustered::tests(), Judged::Uncovered)],
    'in another function' => [fn(): JudgedMutant => cartSurvivor(11, Verdicts::diff('return false;', ''), Family::RemovedCall, Clustered::tests())],
]);

it('reads the same tests in any order as the same', function (): void {
    $survivors = [
        cartSurvivor(16, Verdicts::diff('$this->log->write($order);', ''), Family::RemovedCall, TestIds::of(TestId::of('CartTest::a'), TestId::of('CartTest::b'))),
        cartSurvivor(17, Verdicts::diff('$this->events->dispatch($order);', ''), Family::RemovedCall, TestIds::of(TestId::of('CartTest::b'), TestId::of('CartTest::a'))),
    ];

    expect(array_values(clustersOf(Clustering::of($survivors, Clustered::sources()))))->toHaveCount(2)
        ->and(array_values(array_unique(clustersOf(Clustering::of($survivors, Clustered::sources())))))->toHaveCount(1);
});

it('clusters nothing in code outside any function', function (): void {
    $source = "<?php\n\nlog(\$a);\nlog(\$b);\n";
    $survivors = [
        cartSurvivor(3, Verdicts::diff('log($a);', ''), Family::RemovedCall, Clustered::tests()),
        cartSurvivor(4, Verdicts::diff('log($b);', ''), Family::RemovedCall, Clustered::tests()),
    ];

    expect(Clustering::of($survivors, ByPath::none()->with(Path::of(Clustered::FILE), Contents::of($source))))->toBe([]);
});

it('clusters nothing in a file it cannot read', function (): void {
    expect(Clustering::of(survivorList(Clustered::survivors()), ByPath::none()))->toBe([]);
});

it('clusters within a file, never across two', function (): void {
    $diff = Verdicts::diff('$this->log->write($order);', '');
    $survivors = [
        JudgedMutant::of(Verdicts::mutant('src/Cart.php:16', 'MethodCallRemoval', Family::RemovedCall, $diff), Judged::Survived)->judgedBy(Clustered::tests()),
        JudgedMutant::of(Verdicts::mutant('src/Basket.php:16', 'MethodCallRemoval', Family::RemovedCall, $diff), Judged::Survived)->judgedBy(Clustered::tests()),
    ];
    $sources = Clustered::sources()->with(Path::of('src/Basket.php'), Contents::of(Clustered::CART));

    expect(Clustering::of($survivors, $sources))->toBe([]);
});
