<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Passing;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('names the directory of a run\'s warm workers beside its results', function (): void {
    expect(WarmDirectory::beside('/app/.mutation-gate/results.jsonl'))->toBe('/app/.mutation-gate/results.jsonl.warm');
});

it('warns of each reason a worker forked a run no child once, and removes the directory with every file in it', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $directory = WarmDirectory::beside($results);
    mkdir($directory);
    file_put_contents(Seat::at($directory, 1)->warned(), "loaded a\nloaded b\n");
    file_put_contents(Seat::at($directory, 2)->warned(), "loaded a\n");
    file_put_contents(Seat::at($directory, 2)->out(), 'printed');

    expect(WarmDirectory::cleared($results))->toEqual(Warnings::of(Warning::that('loaded a'), Warning::that('loaded b')))
        ->and(is_dir($directory))->toBeFalse();
});

it('warns of nothing where the run had no warm workers', function (): void {
    expect(WarmDirectory::cleared(sprintf('%s/results.jsonl', Scratch::directory())))->toEqual(Warnings::none());
});

it('waits for no worker whose process id it cannot read', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $directory = WarmDirectory::beside($results);
    mkdir($directory);
    file_put_contents(Seat::at($directory, 1)->pid(), '');
    $started = hrtime(as_number: true);
    WarmDirectory::cleared($results);

    expect((hrtime(as_number: true) - $started) / Seconds::NANOSECONDS)->toBeLessThan(Seat::WAIT / 2);
});

it('makes the directory of a run\'s warm workers, its owner\'s alone, and keeps one already there as it is', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $directory = WarmDirectory::made($results);
    $mode = fileperms($directory) & 0o777;
    file_put_contents(Seat::at($directory, 1)->out(), 'printed');

    expect($directory)->toBe(WarmDirectory::beside($results))
        ->and($mode)->toBe(0o700)
        ->and(WarmDirectory::made($results))->toBe($directory)
        ->and(file_get_contents(Seat::at($directory, 1)->out()))->toBe('printed');
});

it('waits for a worker to end on its own, signalling it nothing', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $directory = WarmDirectory::made($results);
    file_put_contents(Seat::at($directory, 1)->pid(), (string) Passing::for('0.6'));
    $started = hrtime(as_number: true);
    WarmDirectory::cleared($results);
    $waited = (hrtime(as_number: true) - $started) / Seconds::NANOSECONDS;

    expect($waited)->toBeGreaterThan(0.4)
        ->and($waited)->toBeLessThan(Seat::WAIT / 2)
        ->and(is_dir($directory))->toBeFalse();
});
