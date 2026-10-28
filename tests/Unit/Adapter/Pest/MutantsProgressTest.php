<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\MutantsProgress;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Tests\Support\CountedLooks;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

afterEach(function (): void {
    Scratch::sweep();
});

/** A mutant of src/Money.php planned at a line, by its id. */
function progressMutant(string $id, int $line): PlannedMutant
{
    return PlannedMutant::of($id, DiskPath::of('/p/src/Money.php'), Line::of($line), Line::of($line), PlusToMinus::class, 'diff', DiskPath::of(sprintf('/tmp/%s', $id)));
}

it('tells how many of the planned mutants Pest judged, and one it runs, leaving the looks to the watch it wraps', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    file_put_contents($results, implode('', [
        RecordLine::planned(progressMutant('a', 10)),
        RecordLine::planned(progressMutant('b', 12)),
        RecordLine::planned(progressMutant('c', 14)),
        RecordLine::planned(progressMutant('t', 14), RecordEvent::Twin),
        RecordLine::finished('a', PestStatus::Tested, 1.5),
        RecordLine::arguments('/tmp/b', ['--filter=x']),
    ]));
    $looks = new CountedLooks();
    $progress = new MutantsProgress($results, $looks);
    $progress->look();

    expect($progress->progress())->toBe(sprintf('1 of 3 mutants judged, running /p/src/Money.php:12 (%s)', PlusToMinus::class))
        ->and($looks->looks)->toBe(1);
});

it('tells only how many were judged where none runs, and what its watch tells where no results are there yet', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $none = new MutantsProgress(sprintf('%s/none.jsonl', dirname($results)), new CountedLooks());
    file_put_contents($results, RecordLine::planned(progressMutant('a', 10)));

    expect(new MutantsProgress($results, new CountedLooks())->progress())->toBe('0 of 1 mutants judged')
        ->and($none->progress())->toBe('');
});
