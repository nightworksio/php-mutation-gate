<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('is a kill a ledger proved, killed, with nothing its tests miss, on no changed line to begin with', function (): void {
    $judged = Verdicts::provedKill();
    $id = $judged->mutant()->id()->value();

    expect($judged->judgement())->toBe(MutantJudgement::Killed)
        ->and($judged->isOnChangedLine())->toBeFalse()
        ->and($judged->tests())->toEqual(TestIds::none())
        ->and($judged->hint())->toEqual(Hint::killed())
        ->and($judged->hint())->toEqual(Hint::for(Verdicts::killed(), MutantJudgement::Killed, TestIds::none(), Missing::at(Path::of('src/Money.php'))))
        ->and($judged->reproduce())->toBe(sprintf('vendor/bin/mutation-gate reproduce %s', $id))
        ->and($judged->explain())->toBe(sprintf('vendor/bin/mutation-gate explain %s', $id));
});

it('is on a changed line where the reach holds its line for its file, and nowhere else', function (): void {
    $judged = Verdicts::provedKill();
    $reach = static fn(string $file, int $line): Reach => Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of($file), Lines::of(Line::of($line)));

    expect($judged->within($reach('src/Money.php', 9))->isOnChangedLine())->toBeTrue()
        ->and($judged->within($reach('src/Money.php', 8))->isOnChangedLine())->toBeFalse()
        ->and($judged->within($reach('src/Order.php', 9))->isOnChangedLine())->toBeFalse()
        ->and($judged->within($reach('src/Money.php', 9))->mutant())->toBe($judged->mutant());
});
