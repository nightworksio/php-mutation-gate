<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\Transcript;

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

    public function run(Command $command): Ran
    {
        $ran = $this->shell->run($command);
        $this->transcript->keep($ran->output());

        return $ran;
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
