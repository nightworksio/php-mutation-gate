<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_values;
use function dirname;
use function getenv;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function preg_match;
use function sprintf;

/**
 * A program to run: its arguments, the environment it changes, where a false
 * variable is one it does not inherit, and how long it may take.
 */
final readonly class Command
{
    /**
     * What a Pest the gate starts never inherits besides the gate's own
     * variables: the variables that make a process a paratest worker or a
     * mutant's own run.
     */
    private const array PEST_OWN = ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', Recorder::MUTANT, Recorder::MUTATED];

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
            ...self::uninherited(),
            ...self::withheldFrom(getenv(), $withheld),
            'PATH' => sprintf('%s%s%s', dirname(PHP_BINARY), PATH_SEPARATOR, getenv('PATH')),
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
     * What a Pest the gate starts never inherits: Pest's own variables, and
     * each the gate sets for its plugin, which only the command that needs it
     * sets.
     *
     * @return array<string, false>
     */
    private static function uninherited(): array
    {
        $gate = array_map(static fn(GateVariable $variable): string => $variable->value, GateVariable::cases());

        return array_fill_keys([...self::PEST_OWN, ...$gate], value: false);
    }

    /**
     * Every withheld variable among these, as one the command does not inherit.
     *
     * @param array<string, string> $variables
     * @return array<string, false>
     */
    private static function withheldFrom(array $variables, Withheld $withheld): array
    {
        $kept = [];

        foreach (array_keys($variables) as $name) {
            if (preg_match($withheld->pattern(), $name) === 1) {
                $kept[$name] = false;
            }
        }

        return $kept;
    }
}
