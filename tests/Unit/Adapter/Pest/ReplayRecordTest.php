<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayRecord;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A replay's results file, holding these lines, in a directory of its own. */
function replayResults(string $text): string
{
    $file = sprintf('%s/results.jsonl', Scratch::directory());
    file_put_contents($file, $text);

    return $file;
}

it('reads past a record of no shape it knows, as one cut short, to the records after it', function (): void {
    $file = replayResults(sprintf("{\"event\": \"ran\"}\n%s", RecordLine::ran('src/Money.php', 4)));

    expect(ReplayRecord::in($file, 'src/Money.php')->ran())->toBe(4);
});

it('folds every record the replay\'s process wrote to its killer file into what it ran', function (): void {
    $file = replayResults('');
    file_put_contents(KillerFile::beside($file, 'src/Money.php'), sprintf('%s%s', KillerFile::ran(2), KillerFile::ran(3)));

    $record = ReplayRecord::in($file, 'src/Money.php');

    expect([$record->ran(), $record->failed(), $record->order()])->toEqual([5, false, NotGiven::value()]);
});

it('records nothing where the replay wrote no results file', function (): void {
    $record = ReplayRecord::in(sprintf('%s/none.jsonl', Scratch::directory()), 'src/Money.php');

    expect([$record->ran(), $record->failed()])->toBe([0, false]);
});
