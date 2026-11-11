<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\ServedState;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Serve\ServesState;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\ServesStateFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

// What `outbreak serve` answers of the proofs it holds (ADR-0133): each proof
// kept is answered by its key, whatever else is asked, until it is forgotten,
// and once the daemon stops and starts again it still is. Outbreak's core is the binary
// OUTBREAK_BINARY names; with none, the fake stands in for it.

afterEach(function (): void {
    Scratch::sweep();
});

$daemon = static function (string $root): Process|NotGiven {
    $binary = getenv('OUTBREAK_BINARY');

    if (! is_string($binary)) {
        return NotGiven::value();
    }

    $socket = sprintf('%s/.mutation-gate/outbreak/state.sock', $root);

    if (file_exists($socket)) {
        unlink($socket);
    }

    $process = new Process([$binary, 'serve', $root, '--idle', '30']);
    $process->start();

    for ($tries = 0; $tries < 200 && ! file_exists($socket); $tries++) {
        usleep(10000);
    }

    return $process;
};

it('answers each kept proof by its key, across a restart', function () use ($daemon): void {
    $root = Scratch::directory();
    $process = $daemon($root);
    $state = static fn(): ServesState => $process instanceof Process
        ? ServedState::of($root, posix_geteuid(), Seconds::of(10))
        : ServesStateFake::running();
    $served = $state();

    expect($served->kept(['k1' => 'one', 'k2' => 'two']))->toBeInstanceOf(Written::class)
        ->and($served->proofs('k2', 'k3', 'k1'))->toBe(['k1' => 'one', 'k2' => 'two']);

    if ($process instanceof Process) {
        $process->stop();
        $process = $daemon($root);
        $served = $state();

        expect($served->proofs('k1'))->toBe(['k1' => 'one']);

        if ($process instanceof Process) {
            $process->stop();
        }
    }
});

it('answers no forgotten proof, across a restart', function () use ($daemon): void {
    $root = Scratch::directory();
    $process = $daemon($root);
    $state = static fn(): ServesState => $process instanceof Process
        ? ServedState::of($root, posix_geteuid(), Seconds::of(10))
        : ServesStateFake::running();
    $served = $state();

    expect($served->kept(['k1' => 'one', 'k2' => 'two']))->toBeInstanceOf(Written::class)
        ->and($served->forgotten('k1', 'k3'))->toBeInstanceOf(Written::class)
        ->and($served->proofs('k1', 'k2'))->toBe(['k2' => 'two']);

    if ($process instanceof Process) {
        $process->stop();
        $process = $daemon($root);

        expect($state()->proofs('k1', 'k2'))->toBe(['k2' => 'two']);

        if ($process instanceof Process) {
            $process->stop();
        }
    }
});

it('answers nothing where no daemon runs', function (): void {
    $none = getenv('OUTBREAK_BINARY') === false
        ? ServesStateFake::none()
        : ServedState::of(Scratch::directory(), posix_geteuid(), Seconds::of(1));

    expect($none->proofs('k'))->toBeInstanceOf(NotWritten::class)
        ->and($none->kept(['k' => 'v']))->toBeInstanceOf(NotWritten::class)
        ->and($none->forgotten('k'))->toBeInstanceOf(NotWritten::class);
});
