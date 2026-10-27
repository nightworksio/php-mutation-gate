<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function array_fill_keys;
use function array_last;
use function array_map;
use function file;
use function is_array;
use function is_file;

use NightWorksIO\MutationGate\Core\Native\Selected;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function realpath;

/**
 * What the gate told its Testo launcher, ahead of Testo's own arguments (see
 * LaunchOption): which tests to run, whether to stop after the first that
 * fails, and which files to write a coverage run's times and a listing to,
 * where it names them.
 */
final readonly class Told
{
    /**
     * @param array<string, list<string>> $told      each option's values, by the option
     * @param list<string>                $arguments Testo's own
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

    /** @return list<string> Testo's own arguments */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function selected(): Selected
    {
        $group = $this->last(LaunchOption::Group);
        $filter = $this->last(LaunchOption::Filter);
        $files = $this->told[LaunchOption::File->value];

        return $this->listing() instanceof NotGiven ? Selected::narrowedTo(
            $this->tests(),
            $group instanceof NotGiven ? $group : Group::named($group),
            $filter instanceof NotGiven ? $filter : Filter::matching($filter),
            $files === [] ? NotGiven::value() : array_map($this->real(...), $files),
        ) : Selected::listing();
    }

    /** Whether the run stops after the first test that fails or errors. */
    public function stops(): bool
    {
        return ! $this->last(LaunchOption::Stop) instanceof NotGiven;
    }

    /** The file to write each test's time to, or none where the run times none. */
    public function times(): string|NotGiven
    {
        return $this->last(LaunchOption::Times);
    }

    /** The file to write the suite's tests to, running none, or none where the run is no listing. */
    public function listing(): string|NotGiven
    {
        return $this->last(LaunchOption::Listing);
    }

    /** The tests the tests file lists, by id; none where it names no file. */
    private function tests(): TestIds|NotGiven
    {
        $file = $this->last(LaunchOption::Tests);

        if ($file instanceof NotGiven) {
            return $file;
        }

        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return TestIds::of(...array_map(TestId::of(...), is_array($lines) ? $lines : []));
    }

    /** A test file named on the command line, by its real path, as PHP names the file a class is declared in. */
    private function real(string $file): string
    {
        $real = realpath($file);

        return $real === false ? $file : $real;
    }

    /** The last value the launcher was told for an option, or none. */
    private function last(LaunchOption $option): string|NotGiven
    {
        $values = $this->told[$option->value];

        return $values === [] ? NotGiven::value() : array_last($values);
    }
}
