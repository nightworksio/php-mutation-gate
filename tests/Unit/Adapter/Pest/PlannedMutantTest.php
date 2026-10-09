<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;

/** A mutant Pest planned of a file, leaving it as a mutated copy. */
function plannedOn(string $id, string $file, string $copy): PlannedMutant
{
    return PlannedMutant::of($id, DiskPath::of($file), Line::of(3), Line::of(3), 'Plus', 'diff', DiskPath::of($copy));
}

it('is judged by the first planned mutant on its file and copy, where it is a twin', function (): void {
    $twin = plannedOn('t', '/p/A.php', '/tmp/1')->asTwin();
    $planned = [
        plannedOn('t0', '/p/A.php', '/tmp/1')->asTwin(),
        plannedOn('b', '/p/B.php', '/tmp/1'),
        plannedOn('a', '/p/A.php', '/tmp/1'),
        plannedOn('c', '/p/A.php', '/tmp/1'),
    ];

    expect($twin->judgedBy($planned)->id())->toBe('a')
        ->and($twin->isTwin())->toBeTrue()
        ->and(plannedOn('t', '/p/A.php', '/tmp/1')->isTwin())->toBeFalse();
});

it('is judged by its own run where it is no twin, or no mutant on its copy was planned', function (): void {
    $own = plannedOn('c', '/p/A.php', '/tmp/1');
    $alone = plannedOn('t', '/p/A.php', '/tmp/2')->asTwin();
    $planned = [plannedOn('a', '/p/A.php', '/tmp/1'), $own];

    expect($own->judgedBy($planned)->id())->toBe('c')
        ->and($alone->judgedBy($planned)->id())->toBe('t');
});
