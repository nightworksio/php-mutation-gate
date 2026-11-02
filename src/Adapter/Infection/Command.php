<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_values;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A PHP program to run: its arguments, the environment it sets, the
 * variables it never inherits, and how long it may take.
 *
 * @internal
 */
final readonly class Command
{
    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function __construct(
        private array $arguments,
        private array $environment,
        private Withheld $withheld,
        private Seconds|Unlimited $deadline,
        private SilenceLimit|NotGiven $silence = new NotGiven(),
    ) {
    }

    /** A script, run on the PHP that runs the gate, withholding what every run withholds. */
    public static function php(string ...$arguments): self
    {
        return new self([PHP_BINARY, ...array_values($arguments)], [], Withheld::standard(), Unlimited::time());
    }

    /** @param array<string, string> $environment */
    public function with(array $environment): self
    {
        return new self(
            $this->arguments,
            [...$this->environment, ...$environment],
            $this->withheld,
            $this->deadline,
            $this->silence,
        );
    }

    /** This command, withholding these variables as well as those it already withholds. */
    public function withholding(Withheld $withheld): self
    {
        return new self(
            $this->arguments,
            $this->environment,
            $this->withheld->and($withheld),
            $this->deadline,
            $this->silence,
        );
    }

    public function within(Seconds|Unlimited $deadline): self
    {
        return new self($this->arguments, $this->environment, $this->withheld, $deadline, $this->silence);
    }

    /**
     * This command, started through the script that writes the most memory
     * its processes held to this file (see PeakLauncher).
     */
    public function launchedBy(string $launcher, string $peak): self
    {
        return new self(
            [PHP_BINARY, $launcher, $peak, ...$this->arguments],
            $this->environment,
            $this->withheld,
            $this->deadline,
            $this->silence,
        );
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

    public function withheld(): Withheld
    {
        return $this->withheld;
    }

    public function deadline(): Seconds|Unlimited
    {
        return $this->deadline;
    }

    /** This command, also stopped once it has made no progress for this silence limit. */
    public function silencedAfter(SilenceLimit $silence): self
    {
        return new self($this->arguments, $this->environment, $this->withheld, $this->deadline, $silence);
    }

    /** How long it may go without progress, and what shows its progress; none where only its deadline stops it. */
    public function silence(): SilenceLimit|NotGiven
    {
        return $this->silence;
    }
}
