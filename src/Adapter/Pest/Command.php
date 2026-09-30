<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/** A program to run: its arguments, the environment it adds, and how long it may take. */
final readonly class Command
{
    private const string PEST = 'vendor/bin/pest';

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

    public static function of(string ...$arguments): self
    {
        return new self(array_values($arguments), [], Unlimited::time());
    }

    /** Pest, run on the PHP that runs the gate. */
    public static function pest(string ...$arguments): self
    {
        return self::of(PHP_BINARY, self::PEST, ...$arguments);
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
