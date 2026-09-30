<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Recorded;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Report\ReproductionText;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use Pest\Mutate\Mutators\Arithmetic\MinusToPlus;

/** The survivor on `src/Money.php:7`, with this status. */
$survivor = static fn(MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'Pest\Mutate\Mutators\Equality\LessToLessOrEqual', '-a', 0),
    'n1',
    Location::of(Path::of('src/Money.php'), Line::of(7), Line::of(7)),
    Mutation::of('Pest\Mutate\Mutators\Equality\LessToLessOrEqual', MutatorFamily::Boundary, "-        return \$a < \$b;\n+        return \$a <= \$b;\n"),
    $status,
    Seconds::of(0.3),
);

$recorded = static fn(Mutant $mutant): Recorded => Recorded::in(
    Scope::branch('main'),
    Proof::of(Digest::sha256Of('proof'), Path::of('src/Money.php'), Mutants::of($mutant), Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base'))),
    $mutant,
);

it('names the tests that killed it now, why the run said what it did, and the group that judged it', function () use ($survivor, $recorded): void {
    $now = $survivor(MutantStatus::Killed)
        ->killedBy(TestIds::of(TestId::of('Tests\MoneyTest::adds'), TestId::of('Tests\MoneyTest::limits')))
        ->because(Reason::that('Killed on the second try.'));
    $id = $now->id()->value();

    expect(ReproductionText::of($recorded($survivor(MutantStatus::Survived)), Group::named('holds:src/Money.php'), Reproduction::among($now->id(), Mutants::of($now), Reason::that('Not made.'), "  Mutations: 1 tested\n")))->toBe(implode("\n", [
        sprintf('src/Money.php:7  LessToLessOrEqual  %s', $id),
        '    -        return $a < $b;',
        '    +        return $a <= $b;',
        '    Recorded: survived, by github:7/1 on main at 2026-09-29T10:00:00Z',
        '    Now: killed',
        '    Why: Killed on the second try.',
        '    Killed by: Tests\MoneyTest::adds, Tests\MoneyTest::limits',
        '    Judged by: the tests in the group holds:src/Money.php',
        sprintf('    Explain: vendor/bin/mutation-gate explain %s', $id),
        '',
        'What the runner printed:',
        '  Mutations: 1 tested',
    ]));
});

it('says the run made no such mutant, names the filter that judged it, and leaves out a diff that is empty', function () use ($recorded): void {
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '', 0),
        '',
        Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );

    expect(ReproductionText::of($recorded($mutant), Filter::matching('MoneyTest'), Reproduction::among($mutant->id(), Mutants::none(), Reason::that('Run again, the fake made no mutant with this id.'), '')))
        ->toContain("    Now: not made. Run again, the fake made no mutant with this id.\n    Judged by: the tests matching MoneyTest\n");
});

it('heads a kill a ledger proved by its mutator, with no diff, since the ledger keeps none', function (): void {
    $kill = ProvedKill::of(MutantId::hash(Path::of('src/Money.php'), 'Minus', '-b', 0), Path::of('src/Money.php'), Line::of(12), MinusToPlus::class, TestIds::of(TestId::of('Tests\MoneyTest::adds')));
    $recorded = Recorded::in(
        Scope::branch('main'),
        Proof::held(Digest::sha256Of('proof'), Path::of('src/Money.php'), Mutants::none(), ProvedKills::of($kill), Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base'))),
        $kill,
    );
    $id = $kill->id()->value();

    expect(ReproductionText::of($recorded, Group::named('holds:src/Money.php'), Reproduction::among($kill->id(), Mutants::none(), Reason::that('Not made.'), 'said')))->toStartWith(implode("\n", [
        sprintf('src/Money.php:12  MinusToPlus  %s', $id),
        '    Recorded: killed, by github:7/1 on main at 2026-09-29T10:00:00Z',
        '    Now: not made. Not made.',
    ]));
});
