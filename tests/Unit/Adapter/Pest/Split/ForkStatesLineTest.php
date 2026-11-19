<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ForkStatesLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** What a stream's results file holding these lines tells the verdicts read from it. */
function forkStatesRead(string $directory, string ...$lines): StreamVerdicts
{
    $file = sprintf('%s/stream-%d.jsonl', $directory, count($lines));
    file_put_contents($file, implode('', $lines));

    return StreamVerdicts::none()->reading($file);
}

it('writes how many forks read their state and found it alike to the results the gate names, and nothing where none read it or no file is named', function (): void {
    $directory = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $directory);
    $counted = forkStatesRead(
        $directory,
        StreamLine::state('m', 'T::one', child: false, digest: 'a'),
        StreamLine::state('m', 'T::one', child: true, digest: 'a'),
    );

    ForkStatesLine::writtenFor(forkStatesRead($directory), $results);
    ForkStatesLine::writtenFor($counted, results: false);
    ForkStatesLine::writtenFor($counted, '');
    $none = is_file($results);
    ForkStatesLine::writtenFor($counted, $results);

    expect($none)->toBeFalse()
        ->and(file_get_contents($results))->toBe(RecordLine::forkStates(1, 1));
});
