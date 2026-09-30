<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use ArrayObject;

use function implode;

/**
 * What the commands a runner ran for one reproduction printed, in the order
 * they ran. Each adapter's shell writes to it, because adapters share no code
 * and the keeping of the printed text is the same for every runner.
 */
final readonly class Transcript
{
    /** @param ArrayObject<int, string> $printed */
    private function __construct(private ArrayObject $printed)
    {
    }

    public static function empty(): self
    {
        return new self(new ArrayObject());
    }

    /** Keep what one more command printed. */
    public function keep(string $printed): void
    {
        $this->printed->append($printed);
    }

    /** What every command kept so far printed, one after another. */
    public function printed(): string
    {
        return implode("\n", $this->printed->getArrayCopy());
    }
}
