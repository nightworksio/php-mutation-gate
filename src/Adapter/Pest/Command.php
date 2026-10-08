<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_fill_keys;
use function array_filter;
use function array_map;
use function array_values;
use function getenv;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\Runner\SearchPath;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Runner\WorkerVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * A program to run: its arguments, the environment it changes, where a false
 * variable is one it does not inherit, and how long it may take.
 */
final readonly class Command
{
    /**
     * @param list<string>                $arguments
     * @param array<string, string|false> $environment
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

    /**
     * Pest's script, run on the PHP that runs the gate, with that PHP first
     * on the path, because Pest starts each mutant's own run through the same
     * script, which finds `php` there. The project's tests never see a
     * variable withheld.
     */
    public static function pest(string $script, Withheld $withheld, string ...$arguments): self
    {
        return self::php($withheld, $script, ...$arguments);
    }

    /** The PHP that runs the gate, started as it starts Pest's script. */
    public static function php(Withheld $withheld, string ...$arguments): self
    {
        return self::of(PHP_BINARY, ...$arguments)->with([
            ...array_filter(
                Withholding::of($withheld->and(Withheld::otherRuns()), getenv()),
                static fn(string|false $value): bool => $value === false,
            ),
            ...self::unset(),
            SearchPath::VARIABLE => SearchPath::phpFirst(sprintf('%s', getenv(SearchPath::VARIABLE))),
        ]);
    }

    /** @param array<string, string|false> $environment */
    public function with(array $environment): self
    {
        return new self($this->arguments, [...$this->environment, ...$environment], $this->deadline);
    }

    public function within(Seconds|Unlimited $deadline): self
    {
        return new self($this->arguments, $this->environment, $deadline);
    }

    /**
     * This command, started through the script that writes the most memory
     * its processes held to this file (see PeakLauncher).
     */
    public function launchedBy(string $launcher, string $peak): self
    {
        return new self([PHP_BINARY, $launcher, $peak, ...$this->arguments], $this->environment, $this->deadline);
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return array<string, string|false> */
    public function environment(): array
    {
        return $this->environment;
    }

    public function deadline(): Seconds|Unlimited
    {
        return $this->deadline;
    }

    /**
     * The variables the gate sets for its plugin, pest-plugin-mutate's own,
     * and those that make a process another run's worker, each unset whether
     * or not the environment holds it, since a process also inherits what
     * `$_ENV` holds, which `getenv()` does not show. Only the command that
     * needs one sets it.
     *
     * @return array<string, false>
     */
    private static function unset(): array
    {
        $gate = array_map(static fn(GateVariable $variable): string => $variable->value, GateVariable::cases());

        $names = [...$gate, Recorder::MUTANT, Recorder::MUTATED, ...WorkerVariable::names()];

        return array_fill_keys($names, value: false);
    }
}
