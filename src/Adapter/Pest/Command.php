<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function array_values;
use function dirname;
use function getenv;

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
     * What a Pest the gate starts never inherits: the variables that make a
     * process a paratest worker or a mutant's own run, and the ones the gate
     * sets for its own plugin, each of which only the command that needs it
     * sets.
     */
    private const array INHERITED = [
        'PARATEST' => false,
        'TEST_TOKEN' => false,
        'UNIQUE_TEST_TOKEN' => false,
        'PEST_MUTATION_TESTING' => false,
        'PEST_MUTATION_FILE' => false,
        'MUTATION_GATE_RESULTS' => false,
        'MUTATION_GATE_SHARED_COVERAGE' => false,
        'MUTATION_GATE_SUITE_SECONDS' => false,
        'MUTATION_GATE_CANARY' => false,
        'MUTATION_GATE_GUARD' => false,
    ];

    /** The CI's credentials: AWS's, GitHub's and SonarCloud's tokens, and the Actions runtime's. */
    private const string SECRET = '~^(?:AWS_.*|ACTIONS_.*|GITHUB_TOKEN|SONAR_TOKEN)$~';

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
     * script, which finds `php` there. The project's tests never see the CI's
     * credentials.
     */
    public static function pest(string $script, string ...$arguments): self
    {
        return self::of(PHP_BINARY, $script, ...$arguments)->with([
            ...self::INHERITED,
            ...self::secretsIn(getenv()),
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
     * Every credential among these variables, as one the command does not inherit.
     *
     * @param array<string, string> $variables
     * @return array<string, false>
     */
    private static function secretsIn(array $variables): array
    {
        $secrets = [];

        foreach (array_keys($variables) as $name) {
            if (preg_match(self::SECRET, $name) === 1) {
                $secrets[$name] = false;
            }
        }

        return $secrets;
    }
}
