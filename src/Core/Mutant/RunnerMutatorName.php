<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function preg_replace;

/**
 * A mutator as a runner names it: Pest's class, the default set's
 * `default/<Name>`, or Infection's own name.
 */
final readonly class RunnerMutatorName
{
    /** What comes before a mutator's short name in the name a runner gives it. */
    private const string QUALIFIER = '~^.*[\\\\/]~';

    private function __construct(private string $name)
    {
    }

    public static function of(string $name): self
    {
        return new self($name);
    }

    /** The name as the runner gives it. */
    public function value(): string
    {
        return $this->name;
    }

    /** The last part of the name, after its last `\` or `/`: the same for every runner's name of one change. */
    public function short(): string
    {
        return (string) preg_replace(self::QUALIFIER, '', $this->name);
    }
}
