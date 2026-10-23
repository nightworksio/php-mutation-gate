<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_fill_keys;
use function array_last;
use function array_map;
use function file;
use function is_array;
use function is_file;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;

use function realpath;

/**
 * What the gate told its PhpSpec launcher, ahead of PhpSpec's own arguments
 * (see LaunchOption): which examples to run, and which files to write the
 * run's coverage and listing to, where it names them.
 */
final readonly class Told
{
    /**
     * @param array<string, list<string>> $told      each option's values, by the option
     * @param list<string>                $arguments PhpSpec's own
     */
    private function __construct(private array $told, private array $arguments)
    {
    }

    /** @param list<string> $arguments the launcher's, after the autoloader */
    public static function from(array $arguments): self
    {
        $told = array_fill_keys(
            array_map(static fn(LaunchOption $option): string => $option->value, LaunchOption::cases()),
            [],
        );
        $own = [];

        foreach ($arguments as $argument) {
            $option = LaunchOption::setBy($argument);
            $own = $option instanceof LaunchOption ? $own : [...$own, $argument];

            if ($option instanceof LaunchOption) {
                $told[$option->value][] = $option->valueIn($argument);
            }
        }

        return new self($told, $own);
    }

    /** @return list<string> PhpSpec's own arguments */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function selected(): Selected
    {
        $group = $this->last(LaunchOption::Group);
        $filter = $this->last(LaunchOption::Filter);
        $specs = $this->told[LaunchOption::Spec->value];

        return new Selected(
            $this->examples(),
            $group instanceof NotGiven ? $group : Group::named($group),
            $filter instanceof NotGiven ? $filter : Filter::matching($filter),
            $specs === [] ? NotGiven::value() : array_map($this->real(...), $specs),
            ! $this->listing() instanceof NotGiven,
        );
    }

    /** The file to write the run's coverage to, or none where the run measures none. */
    public function coverage(): string|NotGiven
    {
        return $this->last(LaunchOption::Coverage);
    }

    /** The file to write the suite's examples to, running none, or none where the run is no listing. */
    public function listing(): string|NotGiven
    {
        return $this->last(LaunchOption::Listing);
    }

    /** @return list<string>|NotGiven the examples the examples file lists, by id, or none where none is named */
    private function examples(): array|NotGiven
    {
        $file = $this->last(LaunchOption::Examples);
        $lines = $file instanceof NotGiven || ! is_file($file)
            ? false
            : file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return match (true) {
            $file instanceof NotGiven => $file,
            is_array($lines) => $lines,
            default => [],
        };
    }

    /** A spec file named on the command line, by its real path, as PHP names the file a class is declared in. */
    private function real(string $spec): string
    {
        $real = realpath($spec);

        return $real === false ? $spec : $real;
    }

    /** The last value the launcher was told for an option, or none. */
    private function last(LaunchOption $option): string|NotGiven
    {
        $values = $this->told[$option->value];

        return $values === [] ? NotGiven::value() : array_last($values);
    }
}
