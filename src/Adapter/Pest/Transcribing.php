<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use ArrayObject;

use function implode;

/** A shell that keeps what each command it runs printed, for a reproduction to show. */
final readonly class Transcribing implements Shell
{
    /** @param ArrayObject<int, string> $printed what each command printed, in the order they ran */
    private function __construct(private Shell $shell, private ArrayObject $printed)
    {
    }

    public static function over(Shell $shell): self
    {
        return new self($shell, new ArrayObject());
    }

    public function run(Command $command): Ran
    {
        $ran = $this->shell->run($command);
        $this->printed->append($ran->output());

        return $ran;
    }

    public function in(string $directory): self
    {
        return new self($this->shell->in($directory), $this->printed);
    }

    /** What every command run so far printed, one after another. */
    public function printed(): string
    {
        return implode("\n", $this->printed->getArrayCopy());
    }
}
