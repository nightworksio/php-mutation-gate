<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_fill_keys;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function getenv;
use function is_int;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Client;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Core\Runner\SearchPath;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Runner\WorkerVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * A program to run: its arguments, the environment it changes, where a false
 * variable is one it does not inherit, and how long it may take.
 *
 * @internal
 */
final readonly class Command
{
    /** Where Pest's script stands in a command `pest()` makes: after the PHP that runs it. */
    private const int SCRIPT = 1;

    /**
     * @param list<string>                $arguments
     * @param array<string, string|false> $environment
     */
    private function __construct(
        private array $arguments,
        private array $environment,
        private Seconds|Unlimited $deadline,
        private SilenceLimit|NotGiven $silence = new NotGiven(),
        private int|NotGiven $script = new NotGiven(),
        private bool $everyFile = false,
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
        $php = self::php($withheld, $script, ...$arguments);

        return new self($php->arguments, $php->environment, $php->deadline, $php->silence, self::SCRIPT);
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
        return new self(
            $this->arguments,
            [...$this->environment, ...$environment],
            $this->deadline,
            $this->silence,
            $this->script,
            $this->everyFile,
        );
    }

    public function within(Seconds|Unlimited $deadline): self
    {
        return new self(
            $this->arguments,
            $this->environment,
            $deadline,
            $this->silence,
            $this->script,
            $this->everyFile,
        );
    }

    /**
     * This command, started through the script that writes the most memory
     * its processes held to this file (see PeakLauncher).
     */
    public function launchedBy(string $launcher, string $peak): self
    {
        $launching = [PHP_BINARY, $launcher, $peak];

        return new self(
            [...$launching, ...$this->arguments],
            $this->environment,
            $this->deadline,
            $this->silence,
            is_int($this->script) ? $this->script + count($launching) : $this->script,
            $this->everyFile,
        );
    }

    /**
     * This command, where it runs Pest's script, run by a warm worker of the
     * place its slot tells it, among those sharing this directory, through
     * the client that asks it (see Warm\Client); as it is otherwise, or
     * where it runs through one already.
     */
    public function forkedIn(string $directory): self
    {
        return is_int($this->script) ? new self(
            [
                ...array_slice($this->arguments, 0, $this->script),
                ...OwnScript::PestClient->started(),
                $directory,
                Client::SLOT,
                ...array_slice($this->arguments, $this->script),
            ],
            $this->environment,
            $this->deadline,
            $this->silence,
        ) : $this;
    }

    /**
     * This command, its PHP started with these options before the script it
     * runs, in a process of its own that no warm worker forks.
     */
    public function startingPhpWith(string ...$options): self
    {
        return new self(
            [
                ...array_slice($this->arguments, 0, self::SCRIPT),
                ...array_values($options),
                ...array_slice($this->arguments, self::SCRIPT),
            ],
            $this->environment,
            $this->deadline,
            $this->silence,
            everyFile: $this->everyFile,
        );
    }

    /** This command, which runs Pest over every test file of its suites, as a run that names no test file does. */
    public function loadingEveryTestFile(): self
    {
        return new self(
            $this->arguments,
            $this->environment,
            $this->deadline,
            $this->silence,
            $this->script,
            everyFile: true,
        );
    }

    /** Whether it runs Pest over every test file of its suites, which a booted worker can fork it from (ADR-0075). */
    public function loadsEveryTestFile(): bool
    {
        return $this->everyFile;
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

    /** This command, also stopped once it has made no progress for this silence limit. */
    public function silencedAfter(SilenceLimit $silence): self
    {
        return new self(
            $this->arguments,
            $this->environment,
            $this->deadline,
            $silence,
            $this->script,
            $this->everyFile,
        );
    }

    /** How long it may go without progress, and what shows its progress; none where only its deadline stops it. */
    public function silence(): SilenceLimit|NotGiven
    {
        return $this->silence;
    }

    /**
     * The variables the gate sets for its plugin, pest-plugin-mutate's own,
     * and those that make a process another run's worker, each unset whether
     * or not the environment holds it, since a process also inherits what
     * `$_ENV` holds, which `getenv()` does not show. Only the command that
     * needs one sets it, and a test that starts Pest or a tool that runs it
     * unsets them too, so its run is not taken for a mutant's own.
     *
     * @return array<string, false>
     */
    public static function unset(): array
    {
        $gate = array_map(static fn(GateVariable $variable): string => $variable->value, GateVariable::cases());

        $names = [...$gate, Recorder::MUTANT, Recorder::MUTATED, ...WorkerVariable::names()];

        return array_fill_keys($names, value: false);
    }
}
