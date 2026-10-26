<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Baseline;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Forking;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Request;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Core\NotGiven;

use function pcntl_exec;
use function pcntl_fork;
use function touch;

/**
 * A warm worker's forking for its tests: each run runs as a shell script in
 * the child it forks, and the worker is told the run is done once it forked
 * one; no child at all where no script is given.
 */
final readonly class ShellForking implements Forking
{
    private function __construct(private Seat $seat, private string $script)
    {
    }

    public static function running(Seat $seat, string $script): self
    {
        return new self($seat, $script);
    }

    public static function none(Seat $seat): self
    {
        return new self($seat, '');
    }

    public function forked(Request $request, Baseline|NotGiven $baseline, string $report): int|NotGiven
    {
        $child = $this->script === '' ? NotGiven::value() : pcntl_fork();

        if ($child === 0) {
            pcntl_exec('/bin/sh', ['-c', $this->script]);
        }

        touch($this->seat->stop());

        return $child === -1 ? NotGiven::value() : $child;
    }
}
