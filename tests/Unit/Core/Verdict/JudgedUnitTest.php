<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\ThisRun;

it('is a unit and where its result came from', function (): void {
    $unit = Unit::file(Path::of('src/Money.php'));
    $judged = JudgedUnit::of($unit, Origin::Carried);

    expect($judged->unit())->toBe($unit)
        ->and($judged->origin())->toBe(Origin::Carried);
});

it('names the run whose proof its result came from, and this run until told', function (): void {
    $run = Run::of('github:12345/1', Instant::at(new DateTimeImmutable('2026-09-30T10:00:00Z')), Digest::sha256Of('base'));
    $judged = JudgedUnit::of(Unit::file(Path::of('src/Money.php')), Origin::Proved);

    expect($judged->run())->toEqual(ThisRun::result())
        ->and($judged->withRun($run)->run())->toBe($run)
        ->and($judged->withRun($run)->origin())->toBe(Origin::Proved);
});
