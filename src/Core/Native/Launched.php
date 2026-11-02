<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function array_fill_keys;
use function array_key_exists;
use function array_last;
use function array_map;

use Closure;

use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;
use function str_starts_with;

/**
 * A gate launcher's command line after the project's autoloader, as the
 * gate started it in place of a framework's own script: the values of each
 * option the gate told it, `<option>=<value>`, and the framework's own
 * arguments, in their order, which the launcher hands on.
 */
final readonly class Launched
{
    private const string SET = '%s=%s';

    /**
     * @param array<string, list<string>> $told each option's values, by the option
     * @param list<string>                $own  the framework's
     */
    private function __construct(private array $told, private array $own)
    {
    }

    /** What sets an option: its name, `=` and its value. */
    public static function setting(string $option, string $value): string
    {
        return sprintf(self::SET, $option, $value);
    }

    /**
     * These arguments, each that sets one of these options told to the
     * launcher, and every other the framework's own.
     *
     * @param list<string> $options
     * @param list<string> $arguments
     */
    public static function split(array $options, array $arguments): self
    {
        $told = array_fill_keys($options, []);
        $own = [];

        foreach ($arguments as $argument) {
            $option = self::setBy($options, $argument);

            $own = $option instanceof NotGiven ? [...$own, $argument] : $own;

            if (! $option instanceof NotGiven) {
                $told[$option][] = mb_substr($argument, mb_strlen(self::setting($option, '')));
            }
        }

        return new self($told, $own);
    }

    /** @return list<string> the framework's own arguments */
    public function own(): array
    {
        return $this->own;
    }

    /** @return list<string> every value the launcher was told for an option, in order */
    public function values(string $option): array
    {
        return array_key_exists($option, $this->told) ? $this->told[$option] : [];
    }

    /**
     * The tests a run selects: those listed, where a file lists them, in the
     * group and matching the filter these options name, and declared in the
     * files this option names, each by its path on disk.
     *
     * @param Closure(string): string $onDisk
     */
    public function selecting(
        TestIds|NotGiven $listed,
        string $group,
        string $filter,
        string $files,
        Closure $onDisk,
    ): Selected {
        $grouped = $this->last($group);
        $matching = $this->last($filter);
        $declared = $this->values($files);

        return Selected::narrowedTo(
            $listed,
            $grouped instanceof NotGiven ? $grouped : Group::named($grouped),
            $matching instanceof NotGiven ? $matching : Filter::matching($matching),
            $declared === [] ? NotGiven::value() : array_map($onDisk, $declared),
        );
    }

    /** The last value the launcher was told for an option, or none. */
    public function last(string $option): string|NotGiven
    {
        $values = $this->values($option);

        return $values === [] ? NotGiven::value() : array_last($values);
    }

    /**
     * The option of these an argument sets; none where it sets none of them.
     *
     * @param list<string> $options
     */
    private static function setBy(array $options, string $argument): string|NotGiven
    {
        foreach ($options as $option) {
            if (str_starts_with($argument, self::setting($option, ''))) {
                return $option;
            }
        }

        return NotGiven::value();
    }
}
