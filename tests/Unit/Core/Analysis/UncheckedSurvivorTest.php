<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\DifferingPrint;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

it('leaves a survivor of a differing print with the findings that differ, cut short past 250 characters', function (): void {
    $long = Finding::error(Path::of('src/A.php'), 'long', str_repeat('a', 300));
    $left = UncheckedSurvivor::differing(DifferingPrint::between(Findings::of($long), Findings::none(), 'the warm-up'), Path::of('src/A.php'));
    $reason = $left->reason();
    $said = $reason instanceof NotGiven ? '' : $reason;

    expect([$left->why(), $left->file()->value(), mb_strlen($said)])->toBe([Unchecked::PrintDiffers, 'src/A.php', 250])
        ->and($said)->toStartWith('The print alone reports `long` aaa')
        ->and($said)->toEndWith('…');
});
