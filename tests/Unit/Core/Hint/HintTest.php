<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$source = static fn(): Contents => Contents::of(Verdicts::MONEY);
$missing = static fn(): Missing => Missing::at(Path::of('src/Money.php'));

it('says what the tests miss about a survivor of each family', function (MutatorFamily $family, string $removed, string $added, string $hint) use ($source): void {
    $mutant = Verdicts::mutant('src/Money.php:9', 'Mutator', $family, Verdicts::diff($removed, $added));

    expect(Hint::for($mutant, MutantJudgement::Survived, TestIds::none(), $source())->text())->toBe($hint);
})->with([
    'boundary' => [MutatorFamily::Boundary, 'if ($amount < $limit) {', 'if ($amount <= $limit) {', 'No test uses a value at the boundary of `$amount < $limit`.'],
    'condition' => [MutatorFamily::Condition, 'if ($amount < $limit) {', 'if (! ($amount < $limit)) {', 'These tests run the condition, but none asserts on anything that depends on it.'],
    'logical' => [MutatorFamily::Logical, 'return $a && $b;', 'return $a || $b;', 'No test has exactly one side of the `&&` true.'],
    'arithmetic' => [MutatorFamily::Arithmetic, 'return $a + $b;', 'return $a - $b;', 'No test checks the result of `$a + $b` with a non-zero operand.'],
    'return value' => [MutatorFamily::ReturnValue, 'return true;', 'return false;', 'Tests call `fits()`, but none asserts on what it returns.'],
    'removed call' => [MutatorFamily::RemovedCall, '$this->save($order);', '', 'Every test passes without the call to `save()`, so nothing asserts on its effect.'],
    'removed statement' => [MutatorFamily::RemovedCall, '$total += $price;', '', 'Every test passes without `$total += $price;`, so nothing asserts on its effect.'],
    'literal' => [MutatorFamily::Literal, 'return 3;', 'return 4;', 'No test depends on this value being `3`.'],
    'collection' => [MutatorFamily::Collection, 'return [$a, $b];', 'return [$a];', 'No test notices the item missing, or runs the loop with items and checks the outcome.'],
    'exception' => [MutatorFamily::Exception, 'throw new Refused();', '', 'No test expects this exception.'],
    'unwrap' => [MutatorFamily::Unwrap, 'return array_values($xs);', 'return $xs;', 'No test passes a value `array_values()` would change.'],
    'unwrap of no call' => [MutatorFamily::Unwrap, 'return $xs;', 'return $ys;', 'No test passes a value this call would change.'],
    'visibility' => [MutatorFamily::Visibility, 'public function fits(int $amount, int $limit): bool', 'protected function fits(int $amount, int $limit): bool', 'Nothing outside the class calls `fits()`. It can be narrower.'],
    'no family, changed' => [MutatorFamily::None, 'return $a . $b;', 'return $b . $a;', 'These tests run line 9, but none fails when it becomes `return $b . $a;`.'],
    'no family, removed' => [MutatorFamily::None, 'return $a . $b;', '', 'These tests run line 9, but none fails when it is removed.'],
]);

it('has a sentence for every family', function (MutatorFamily $family) use ($source): void {
    $mutant = Verdicts::mutant('src/Money.php:9', 'Mutator', $family, Verdicts::diff('return true;', 'return false;'));

    expect(Hint::for($mutant, MutantJudgement::Survived, TestIds::none(), $source())->text())->not->toBe('');
})->with(MutatorFamily::cases());

it('says so where there is no function around the mutant, or no file to read', function (MutatorFamily $family, string $hint) use ($missing): void {
    $mutant = Verdicts::mutant('src/Money.php:9', 'Mutator', $family, Verdicts::diff('return true;', 'return false;'));

    expect(Hint::for($mutant, MutantJudgement::Survived, TestIds::none(), $missing())->text())->toBe($hint)
        ->and(Hint::for($mutant, MutantJudgement::Survived, TestIds::none(), Contents::of("<?php\nreturn true;\n"))->text())->toBe($hint);
})->with([
    'return value' => [MutatorFamily::ReturnValue, 'Tests run this return, but none asserts on what it returns.'],
    'visibility' => [MutatorFamily::Visibility, 'Nothing outside the class calls this. It can be narrower.'],
]);

it('names up to three judging tests of a mutant counted as not killed, then how many more', function (int $tests, string $named) use ($source): void {
    $ids = [];

    for ($test = 1; $test <= $tests; ++$test) {
        $ids[] = TestId::of(sprintf('T%d', $test));
    }

    $mutant = Verdicts::mutant('src/Money.php:9', 'Mutator', MutatorFamily::Exception, Verdicts::diff('throw new Refused();', ''));

    expect(Hint::for($mutant, MutantJudgement::Survived, TestIds::of(...$ids), $source())->text())
        ->toBe(sprintf('No test expects this exception. It is judged by %s.', $named))
        ->and(Hint::for($mutant, MutantJudgement::Flaky, TestIds::of(...$ids), $source())->text())
        ->toBe(sprintf('Its tests killed it on one run and let it survive on another, so they are the suspects. It is judged by %s.', $named))
        ->and(Hint::for($mutant, MutantJudgement::Unjudged, TestIds::of(...$ids), $source())->text())
        ->toBe(sprintf('Nothing judged it before the run stopped, so it counts as not killed. It is judged by %s.', $named))
        ->and(Hint::for($mutant, MutantJudgement::TooSlowToJudge, TestIds::of(...$ids), $source())->text())
        ->toEndWith(sprintf('or raise `timeouts.seconds`. It is judged by %s.', $named));
})->with([
    'one' => [1, '`T1`'],
    'two' => [2, '`T1` and `T2`'],
    'three' => [3, '`T1`, `T2` and `T3`'],
    'four' => [4, '`T1`, `T2`, `T3` and 1 more'],
    'six' => [6, '`T1`, `T2`, `T3` and 3 more'],
]);

it('says what each other judgement means', function (MutantJudgement $judgement, string $hint) use ($source): void {
    $mutant = Verdicts::mutant('src/Order.php:8', 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('return $a + $b;', 'return $a - $b;'));

    expect(Hint::for($mutant, $judgement, TestIds::none(), $source())->text())->toBe($hint);
})->with([
    'uncovered' => [MutantJudgement::Uncovered, 'No test runs line 8.'],
    'killed' => [MutantJudgement::Killed, 'A test fails with it in place.'],
    'errored' => [MutantJudgement::Errored, 'It crashes its tests, which counts as killed.'],
    'killed by timeout' => [MutantJudgement::KilledByTimeout, 'Its tests ran far past their usual time with it in place, so the timeout counts as a kill.'],
    'unjudged' => [MutantJudgement::Unjudged, 'Nothing judged it before the run stopped, so it counts as not killed.'],
    'too slow to judge' => [MutantJudgement::TooSlowToJudge, 'Its tests take half its time limit or more, so a timeout says nothing about it. Hold `src/Order.php` with a group of the tests that assert on it, or raise `timeouts.seconds`.'],
    'ignored' => [MutantJudgement::Ignored, 'An ignore in the config leaves it out of the score.'],
    'ignored by a marker' => [MutantJudgement::IgnoredByMarker, 'A native ignore marker leaves it out of the score.'],
]);

it('keeps a hint as it was written', function (): void {
    expect(Hint::that('No test runs line 3.')->text())->toBe('No test runs line 3.');
});

it('names no test of a mutant that was killed, left out or never run', function (MutantJudgement $judgement) use ($source): void {
    $mutant = Verdicts::mutant('src/Order.php:8', 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('return $a + $b;', 'return $a - $b;'));

    expect(Hint::for($mutant, $judgement, TestIds::of(TestId::of('OrderTest::adds')), $source())->text())->not->toContain('OrderTest');
})->with([
    MutantJudgement::Uncovered,
    MutantJudgement::Killed,
    MutantJudgement::Errored,
    MutantJudgement::KilledByTimeout,
    MutantJudgement::Ignored,
    MutantJudgement::IgnoredByMarker,
]);
