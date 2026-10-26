<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Baseline;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Finish;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Forking;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Request;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Worker;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('starts fresh, and warns of it, a run whose child the system no longer tells of', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $seat = Seat::at(WarmDirectory::beside($results), 1);
    mkdir($seat->directory());
    Request::of('42-1', (int) getmypid(), ['vendor/bin/pest'], [], (string) getcwd())->leftIn($seat->request());
    $served = Worker::at($seat->directory(), 1, (int) getmypid())->served(new readonly class ($seat) implements Forking {
        public function __construct(private Seat $seat)
        {
        }

        public function forked(Request $request, Baseline|NotGiven $baseline, string $report): int
        {
            touch($this->seat->stop());

            return 1;
        }
    });
    $finish = Finish::readFrom($seat->ended('42-1'));
    unlink($seat->pid());

    expect($served)->toBe(0)
        ->and($finish instanceof Finish && $finish->isRefused())->toBeTrue()
        ->and(WarmDirectory::cleared($results))->toEqual(Warnings::of(Warning::that('The warm worker lost track of a child, so its mutant ran fresh.')));
});
