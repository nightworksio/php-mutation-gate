<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Removal\Removals;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Tests\Support\Removing;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** The name of the callee a finding suggests deleting; nothing where it suggests none. */
function removableName(Removable|NoFinding $found): string
{
    return $found instanceof Removable ? $found->name() : '';
}

it('suggests deleting the callee of a whole-statement call its class, imports or namespace lead to', function (string $statement, string $callee): void {
    expect(removableName(Removing::found(Removing::removal($statement), Removing::bodies(), Removing::strong())))->toBe($callee);
})->with([
    'a method on $this' => ['$this->record($amount);', 'record'],
    'a method on self' => ['self::record($amount);', 'record'],
    'a method on static' => ['static::record($amount);', 'record'],
    'a static method of an imported class' => ['Log::write($amount);', 'write'],
    'a function of the namespace, before a global one of the name' => ['tally($amount);', 'tally'],
    'a global function, named fully qualified' => ['\\total($amount);', 'total'],
]);

it('suggests nothing of a call no name leads to a callee the project declares', function (string $statement): void {
    expect(Removing::found(Removing::removal($statement), Removing::bodies(), Removing::strong()))->toEqual(NoFinding::survivor());
})->with([
    'a method on another object' => ['$other->record($amount);'],
    'a parent\'s method' => ['parent::record($amount);'],
    'a method of an anonymous class' => ['$this->note();'],
    'a function no file declares' => ['nowhere($amount);'],
    'a method of a class no file declares' => ['Unknown::write($amount);'],
    'a method a trait calls on $this' => ['$this->bump();'],
    'a method a trait calls on static' => ['static::bump();'],
    'a global function, unqualified inside a namespace' => ['total($amount);'],
]);

it('suggests nothing of a removal that is not of one whole call statement', function (JudgedMutant $removal): void {
    expect(Removing::found($removal, Removing::bodies(), Removing::strong()))->toEqual(NoFinding::survivor());
})->with([
    'a chained call' => [Removing::removal('$this->record($amount)->done();')],
    'a call whose result is assigned' => [Removing::removal('$total = $this->record($amount);')],
    'a call replaced, not removed' => [JudgedMutant::of(Verdicts::mutant(
        sprintf('%s:%d', Removing::CART, Removing::lineOf(Removing::CART, '$this->record($amount);')),
        'CloneRemoval',
        MutatorFamily::RemovedCall,
        Verdicts::diff('$this->record($amount);', '$this;'),
    ), MutantJudgement::Survived)],
    'a removal of another family' => [Removing::removal('$this->record($amount);', family: MutatorFamily::None)],
    'a removal not counted as survived' => [Removing::removal('$this->record($amount);', MutantJudgement::Uncovered)],
]);

it('suggests nothing of a callee whose body holds no other mutant than the removal itself', function (string $statement): void {
    expect(Removing::found(Removing::removal($statement), Removing::bodies(), Removing::strong()))->toEqual(NoFinding::survivor());
})->with([
    'an empty body' => ['$this->idle();'],
    'a call of the function it is in' => ['$this->repeat();'],
]);

it('reads a survivor of the callee\'s body as evidence, and one proven equivalent or ignored as none', function (bool $removable, MutantJudgement ...$judgements): void {
    $others = [];

    foreach ($judgements as $at => $judgement) {
        $others[] = Removing::mutantOf(Removing::CART, '$this->total = 1;', $judgement, sprintf('Mutator%d', $at));
    }

    expect(removableName(Removing::found(Removing::removal('$this->checked();'), $others, Removing::strong())))->toBe($removable ? 'checked' : '');
})->with([
    'a survivor' => [true, MutantJudgement::Survived],
    'only one proven equivalent' => [false, MutantJudgement::Equivalent],
    'one proven equivalent, and a survivor' => [true, MutantJudgement::Equivalent, MutantJudgement::Survived],
    'one ignored, and a survivor' => [true, MutantJudgement::Ignored, MutantJudgement::Survived],
    'one ignored by a marker, and a survivor' => [true, MutantJudgement::IgnoredByMarker, MutantJudgement::Survived],
]);

it('suggests nothing of a callee any other mutant of whose body its tests did not let through', function (MutantJudgement $judgement): void {
    $others = [
        Removing::mutantOf(Removing::CART, '$this->total = 1;', MutantJudgement::Survived),
        Removing::mutantOf(Removing::CART, '$this->total = 1;', $judgement, 'Other'),
    ];

    expect(Removing::found(Removing::removal('$this->checked();'), $others, Removing::strong()))->toEqual(NoFinding::survivor());
})->with([
    MutantJudgement::Killed,
    MutantJudgement::Errored,
    MutantJudgement::KilledByTimeout,
    MutantJudgement::KilledByStaticAnalysis,
    MutantJudgement::Uncovered,
    MutantJudgement::Unjudged,
    MutantJudgement::Flaky,
    MutantJudgement::TooSlowToJudge,
]);

it('suggests deleting only where a test judges the removal and none of those is weak', function (bool $removable, TestId ...$tests): void {
    expect(removableName(Removing::found(Removing::removal('$this->record($amount);'), Removing::bodies(), ...$tests)))
        ->toBe($removable ? 'record' : '');
})->with([
    'a test that asserts a value' => [true, Removing::strong()],
    'a test not assessed, that calls a helper' => [true, Removing::helped()],
    'no test' => [false],
    'a weak test' => [false, Removing::weak()],
    'a weak test beside one that asserts a value' => [false, Removing::strong(), Removing::weak()],
]);

it('suggests nothing where the removal\'s own file was not read', function (): void {
    $removal = Removing::removal('$this->record($amount);');
    $found = Removals::findings(
        Removing::trees($removal, ...Removing::bodies()),
        Removing::matrix($removal, Removing::strong()),
        ByPath::none(),
        Removing::testFiles(),
    );

    expect($found->of($removal->mutant()->id()))->toEqual(NoFinding::survivor());
});
