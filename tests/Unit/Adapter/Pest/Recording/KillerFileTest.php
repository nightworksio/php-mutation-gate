<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

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

it('writes a test on a line of its own after its record, with a line break in its name kept on it', function (): void {
    expect(KillerFile::line(RecordEvent::Killed, 'P\Tests\MoneySpec::__pest_evaluable_it_adds'))
        ->toBe("killed P%5CTests%5CMoneySpec%3A%3A__pest_evaluable_it_adds\n")
        ->and(KillerFile::line(RecordEvent::Errored, "Tests\\LegacySpec::testAdds with data set \"a\nb\""))
        ->toBe("errored Tests%5CLegacySpec%3A%3AtestAdds%20with%20data%20set%20%22a%0Ab%22\n");
});

it('takes the record of every test a file names, in the order written, and removes the file', function (): void {
    $file = sprintf('%s/results.jsonl.abc.killers', Scratch::directory());
    file_put_contents($file, sprintf(
        '%s%s',
        KillerFile::line(RecordEvent::Errored, 'P\Tests\MoneySpec::first'),
        KillerFile::line(RecordEvent::Killed, "Tests\\LegacySpec::testAdds#(1)\na"),
    ));

    expect(KillerFile::taken($file, '/m/a.php'))->toBe([
        RecordLine::errored('/m/a.php', 'P\Tests\MoneySpec::first'),
        RecordLine::killed('/m/a.php', "Tests\\LegacySpec::testAdds#(1)\na"),
    ])->and(is_file($file))->toBeFalse();
});

it('takes no record from a line that names no test as failed or errored', function (): void {
    $file = sprintf('%s/results.jsonl.abc.killers', Scratch::directory());
    file_put_contents($file, "{\"mutated\":\"/m/a.php\"}\n\nplanned P%5CTests\nkilled\n");

    expect(KillerFile::taken($file, '/m/a.php'))->toBe([RecordLine::killed('/m/a.php', '')]);
});

it('takes no record from a last line with no line break after it, as a process stopped while it wrote leaves one', function (): void {
    $file = sprintf('%s/results.jsonl.abc.killers', Scratch::directory());
    file_put_contents($file, sprintf('%skilled P%%5CTests%%5CMon', KillerFile::line(RecordEvent::Killed, 'P\Tests\MoneySpec::first')));

    expect(KillerFile::taken($file, '/m/a.php'))->toBe([RecordLine::killed('/m/a.php', 'P\Tests\MoneySpec::first')]);
});

it('takes no record where there is no file', function (): void {
    expect(KillerFile::taken(sprintf('%s/results.jsonl.abc.killers', Scratch::directory()), '/m/a.php'))->toBe([]);
});
