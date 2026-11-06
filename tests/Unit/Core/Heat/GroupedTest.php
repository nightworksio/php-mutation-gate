<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Heat\Grouped;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('groups each mutant and every kill under the name its key gives, leaving out those it names nothing for', function (): void {
    $survivor = static fn(int $line): JudgedMutant => JudgedMutant::of(
        Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line)),
        MutantJudgement::Survived,
    );
    $kill = static fn(int $line): JudgedKill => JudgedKill::of(ProvedKill::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf('-%d', $line), $line),
        Path::of('src/Money.php'),
        Line::of($line),
        'Plus',
        TestIds::none(),
    ));
    $mutants = JudgedMutants::of($survivor(1), $survivor(5), $survivor(9))->and(JudgedMutants::kills($kill(6), $kill(7), $kill(10)));
    $groups = Grouped::by(
        $mutants,
        static fn(JudgedMutant|JudgedKill $mutant): string|Nameless => match (true) {
            $mutant->mutant()->location()->start()->number() < 5 => Nameless::code(),
            $mutant->mutant()->location()->start()->number() < 9 => 'Money::add',
            default => 'Money::sub',
        },
    );

    expect(array_map(count(...), $groups))->toBe(['Money::add' => 3, 'Money::sub' => 2]);
});
