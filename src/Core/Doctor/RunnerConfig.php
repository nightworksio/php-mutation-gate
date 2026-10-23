<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_values;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What the runner's own config says, as far as the checks ask it: the
 * `memory_limit` the project's PHPUnit config sets, and the Codeception
 * suites that serve the application from another process, whose tests the
 * gate runs none of. A config nobody read says nothing.
 */
final readonly class RunnerConfig
{
    /** @param list<string> $elsewhere */
    private function __construct(private PhpUnitMemory|NotGiven $phpUnitMemory, private array $elsewhere)
    {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), []);
    }

    /** This, with the `memory_limit` the project's PHPUnit config sets, where it sets one. */
    public function withPhpUnitMemory(PhpUnitMemory $memory): self
    {
        return new self($memory, $this->elsewhere);
    }

    /** This, with these suites, which serve the application from another process. */
    public function withSuitesElsewhere(string ...$suites): self
    {
        return new self($this->phpUnitMemory, array_values($suites));
    }

    public function phpUnitMemory(): PhpUnitMemory|NotGiven
    {
        return $this->phpUnitMemory;
    }

    /** @return list<string> the suites that serve the application from another process, by their names */
    public function suitesElsewhere(): array
    {
        return $this->elsewhere;
    }
}
