<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_key_exists;
use function count;

use Countable;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;

/** The mutants static analysis rejected before their tests, each with the finding that rejected it. */
final readonly class Rejections implements Countable
{
    /** @param array<string, Rejection> $rejections by mutant id */
    private function __construct(private array $rejections)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These rejections, and a mutant's more. */
    public function with(MutantId $mutant, Rejection $rejection): self
    {
        return new self([...$this->rejections, $mutant->value() => $rejection]);
    }

    /** What rejected a mutant; nothing where none did. */
    public function of(MutantId $mutant): Rejection|NotGiven
    {
        return array_key_exists($mutant->value(), $this->rejections)
            ? $this->rejections[$mutant->value()]
            : NotGiven::value();
    }

    public function count(): int
    {
        return count($this->rejections);
    }
}
