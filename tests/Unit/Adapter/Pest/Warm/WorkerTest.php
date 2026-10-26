<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Baseline;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Finish;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Forking;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Request;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Worker;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Passing;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellForking;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/** The seat of place 1 of a run's warm workers, its directory made. */
$seat = static function (): Seat {
    $seat = Seat::at(WarmDirectory::beside(sprintf('%s/results.jsonl', Scratch::directory())), 1);
    mkdir($seat->directory());

    return $seat;
};

/** A run's request, asked by this process, of a mutant of this file, or of none. */
$request = static fn(int $asker, string $mutated = ''): Request => Request::of(
    '42-1',
    $asker,
    ['vendor/bin/pest'],
    $mutated === '' ? [] : [Recorder::MUTANT => $mutated],
    (string) getcwd(),
);

/** A pause that pauses for no time, keeping each pause it was asked for. */
$pausing = static fn(ArrayObject $paused): Closure => static function (int $microseconds) use ($paused): void {
    $paused->append($microseconds);
};

/** What the worker of this seat warned of, its own process id forgotten, since it is this process's. */
$warned = static function (Seat $seat): Warnings {
    unlink($seat->pid());

    return WarmDirectory::cleared(sprintf('%s/results.jsonl', dirname($seat->directory())));
};

it('runs a run in a child it forks, and writes how the child ended where the run\'s asker reads it', function () use ($seat, $request, $pausing): void {
    $at = $seat();
    $request((int) getmypid())->leftIn($at->request());
    $served = Worker::at($at->directory(), 1, (int) getmypid(), $pausing($paused = new ArrayObject()))->served(ShellForking::running($at, 'sleep 0.2; exit 3'));
    $finish = Finish::readFrom($at->ended('42-1'));

    expect($served)->toBe(0)
        ->and($finish instanceof Finish ? $finish->code() : $finish)->toBe(3)
        ->and($paused->count())->toBeGreaterThan(0)
        ->and(array_unique($paused->getArrayCopy()))->toBe([Polling::interval()->microseconds()])
        ->and(is_file($at->started('42-1')) && is_file($at->child('42-1')))->toBeTrue();
});

it('stops a child, with every process under it, once the run\'s asker is gone', function () use ($seat, $request, $pausing): void {
    $at = $seat();
    $request(Passing::for('0.3'))->leftIn($at->request());
    Worker::at($at->directory(), 1, (int) getmypid(), $pausing($paused = new ArrayObject()))->served(ShellForking::running($at, 'sleep 30'));
    $finish = Finish::readFrom($at->ended('42-1'));

    expect($finish instanceof Finish ? $finish->signal() : $finish)->toBe(SIGKILL);
});

it('forks no child for a run whose asker is gone, and serves on until its parent is gone', function () use ($seat, $request, $pausing): void {
    $at = $seat();
    $request(Passing::NONE)->leftIn($at->request());
    $served = Worker::at($at->directory(), 1, Passing::for('0.3'), $pausing($paused = new ArrayObject()))->served(ShellForking::none($at));

    expect($served)->toBe(0)
        ->and(is_file($at->request()) || is_file($at->started('42-1')) || is_file($at->ended('42-1')))->toBeFalse()
        ->and($paused->count())->toBeGreaterThan(0)
        ->and(array_unique($paused->getArrayCopy()))->toBe([Polling::interval()->microseconds()])
        ->and(is_file($at->stop()))->toBeFalse();
});

it('starts fresh, and warns of it, the run of a mutant of a file it loaded', function () use ($seat, $request, $warned, $pausing): void {
    $at = $seat();
    $mutated = (string) realpath(Tree::at('src/Adapter/Pest/Warm/Worker.php'));
    $request((int) getmypid(), $mutated)->leftIn($at->request());
    Worker::at($at->directory(), 1, Passing::for('0.3'), $pausing($paused = new ArrayObject()))->served(ShellForking::none($at));
    $finish = Finish::readFrom($at->ended('42-1'));

    expect($finish instanceof Finish && $finish->isRefused())->toBeTrue()
        ->and($warned($at))->toEqual(Warnings::of(Warning::that(sprintf(
            'The warm worker had loaded %s, which a mutant changes, so its mutants ran fresh.',
            $mutated,
        ))));
});

it('starts fresh, and warns of it, a run it could fork no child for', function () use ($seat, $request, $warned, $pausing): void {
    $at = $seat();
    $request((int) getmypid())->leftIn($at->request());
    Worker::at($at->directory(), 1, (int) getmypid(), $pausing($paused = new ArrayObject()))->served(ShellForking::none($at));
    $finish = Finish::readFrom($at->ended('42-1'));

    expect($finish instanceof Finish && $finish->isRefused())->toBeTrue()
        ->and($warned($at))->toEqual(Warnings::of(Warning::that('The warm worker could not fork, so a mutant ran fresh.')));
});

it('starts fresh, and warns of it, a run whose child the system no longer tells of', function () use ($seat, $request, $warned, $pausing): void {
    $at = $seat();
    $request((int) getmypid())->leftIn($at->request());
    $served = Worker::at($at->directory(), 1, (int) getmypid(), $pausing($paused = new ArrayObject()))->served(new readonly class ($at) implements Forking {
        public function __construct(private Seat $seat)
        {
        }

        public function forked(Request $request, Baseline|NotGiven $baseline, string $report): int
        {
            touch($this->seat->stop());

            return 1;
        }
    });
    $finish = Finish::readFrom($at->ended('42-1'));

    expect($served)->toBe(0)
        ->and($finish instanceof Finish && $finish->isRefused())->toBeTrue()
        ->and($warned($at))->toEqual(Warnings::of(Warning::that('The warm worker lost track of a child, so its mutant ran fresh.')));
});
