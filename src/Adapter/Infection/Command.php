<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_values;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/** A PHP program to run: its arguments, the environment it sets, and how long it may take. */
final readonly class Command
{
    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function __construct(
        private array $arguments,
        private array $environment,
        private Seconds|Unlimited $deadline,
    ) {
    }

    /** A script, run on the PHP that runs the gate. */
    public static function php(string ...$arguments): self
    {
        return new self([PHP_BINARY, ...array_values($arguments)], [], Unlimited::time());
    }

    /** @param array<string, string> $environment */
    public function with(array $environment): self
    {
        return new self($this->arguments, [...$this->environment, ...$environment], $this->deadline);
    }

    public function within(Seconds|Unlimited $deadline): self
    {
        return new self($this->arguments, $this->environment, $deadline);
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->environment;
    }

    public function deadline(): Seconds|Unlimited
    {
        return $this->deadline;
    }
}
