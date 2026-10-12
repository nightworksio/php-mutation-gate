<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A killer at the second place of its process's order, in the process with id 7. */
function secondPlace(): Placed
{
    return Placed::at(2, hash('sha256', 'order'), 7);
}

it('keeps each mutated copy\'s killers in a file of its own beside the results, which one pattern matches', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $first = KillerFile::beside($results, '/tmp/mutations/a.php');
    $second = KillerFile::beside($results, '/tmp/mutations/b.php');
    file_put_contents($first, '');
    file_put_contents($second, '');

    expect($first)->toBe(sprintf('%s.%s.killers', $results, hash('sha256', '/tmp/mutations/a.php')))
        ->and($second)->not->toBe($first)
        ->and(glob(KillerFile::everyBeside($results)))->toEqualCanonicalizing([$first, $second]);
});

it('writes a test on a line of its own after its record, with a line break in its name kept on it, then where it stood', function (): void {
    expect(KillerFile::line(RecordEvent::Killed, 'P\Tests\MoneySpec::__pest_evaluable_it_adds', secondPlace()))
        ->toBe(sprintf("killed P%%5CTests%%5CMoneySpec%%3A%%3A__pest_evaluable_it_adds 7 2 %s\n", hash('sha256', 'order')))
        ->and(KillerFile::line(RecordEvent::Errored, "Tests\\LegacySpec::testAdds with data set \"a\nb\"", Placed::unplaced(7)))
        ->toBe("errored Tests%5CLegacySpec%3A%3AtestAdds%20with%20data%20set%20%22a%0Ab%22 7 - -\n");
});

it('takes the record of every line, the preload, each test and the count, in the order written, and removes the file', function (): void {
    $file = sprintf('%s/results.jsonl.abc.killers', Scratch::directory());
    file_put_contents($file, sprintf(
        '%s%s%s%s',
        KillerFile::preloaded(),
        KillerFile::line(RecordEvent::Errored, 'P\Tests\MoneySpec::first', secondPlace()),
        KillerFile::line(RecordEvent::Killed, "Tests\\LegacySpec::testAdds#(1)\na", Placed::unplaced(7)),
        KillerFile::ran(12),
    ));

    expect(KillerFile::taken($file, '/m/a.php'))->toBe([
        RecordLine::preloaded('/m/a.php'),
        RecordLine::errored('/m/a.php', 'P\Tests\MoneySpec::first', secondPlace()),
        RecordLine::killed('/m/a.php', "Tests\\LegacySpec::testAdds#(1)\na", Placed::unplaced(7)),
        RecordLine::ran('/m/a.php', 12),
    ])->and(is_file($file))->toBeFalse();
});

it('takes no record from a line that names no test as failed or errored, nor no place it writes, nor from a count that is no whole number', function (): void {
    $file = sprintf('%s/results.jsonl.abc.killers', Scratch::directory());
    file_put_contents($file, "{\"mutated\":\"/m/a.php\"}\n\nplanned P%5CTests\nran\nran -1\nran 2x\nkilled\nkilled T 7\nkilled T x - -\nkilled T 7 2 -\nkilled T 7 - abc\nkilled  7 - -\nerrored T 7\nerrored T x - -\n");

    expect(KillerFile::taken($file, '/m/a.php'))->toBe([RecordLine::killed('/m/a.php', '', Placed::unplaced(7))]);
});

it('takes no record from a last line with no line break after it, as a process stopped while it wrote leaves one', function (): void {
    $file = sprintf('%s/results.jsonl.abc.killers', Scratch::directory());
    file_put_contents($file, sprintf('%skilled P%%5CTests%%5CMon', KillerFile::line(RecordEvent::Killed, 'P\Tests\MoneySpec::first', secondPlace())));
    $whole = sprintf('%s/results.jsonl.def.killers', Scratch::directory());
    file_put_contents($whole, sprintf('%s%s', KillerFile::preloaded(), mb_rtrim(KillerFile::ran(3))));

    expect(KillerFile::taken($file, '/m/a.php'))->toBe([RecordLine::killed('/m/a.php', 'P\Tests\MoneySpec::first', secondPlace())])
        ->and(KillerFile::taken($whole, '/m/a.php'))->toBe([RecordLine::preloaded('/m/a.php')]);
});

it('takes no record where there is no file', function (): void {
    expect(KillerFile::taken(sprintf('%s/results.jsonl.abc.killers', Scratch::directory()), '/m/a.php'))->toBe([]);
});
