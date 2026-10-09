<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Transcript;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/** A shell that keeps what each command it runs printed, for a reproduction to show. */
final readonly class Transcribing implements Shell
{
    private function __construct(private Shell $shell, private Transcript $transcript)
    {
    }

    public static function over(Shell $shell): self
    {
        return new self($shell, Transcript::empty());
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        $ran = $this->shell->run($command, $watch);
        $this->transcript->keep($ran->output());

        return $ran;
    }

    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        $ends = $this->shell->sideBySide($slots, $startingWithin, ...$commands);

        foreach ($ends as $ran) {
            $this->transcript->keep($ran->output());
        }

        return $ends;
    }

    public function in(string $directory): self
    {
        return new self($this->shell->in($directory), $this->transcript);
    }

    /** What every command run so far printed, one after another. */
    public function printed(): string
    {
        return $this->transcript->printed();
    }
}
