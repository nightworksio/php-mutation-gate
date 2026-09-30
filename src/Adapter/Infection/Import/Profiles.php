<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use function array_diff;
use function array_filter;
use function array_flip;
use function array_key_exists;
use function array_unique;
use function array_values;

use Closure;
use Infection\Mutator\ProfileList;
use NightWorksIO\MutationGate\Adapter\Infection\Families;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;

use function str_starts_with;

/**
 * Infection's mutator profiles, as the installed Infection lists them, and
 * a set of its mutators as the fewest names a gate ignore takes: each family
 * whose every mutator is in the set, then the rest by name (ADR-0016,
 * decision 4).
 */
final readonly class Profiles
{
    /**
     * @param array<string, list<string>> $profiles each profile's mutator classes, and profiles it names
     * @param array<string, string>       $names    each mutator's name, by its class
     */
    private function __construct(private array $profiles, private array $names)
    {
    }

    /**
     * The profiles of the Infection the project installed, or why there are none to read.
     *
     * @param Closure(string): bool $exists whether a class can be loaded
     */
    public static function installed(Closure $exists): self|Unmapped
    {
        return $exists(ProfileList::class)
            ? new self(ProfileList::ALL_PROFILES, array_flip(ProfileList::ALL_MUTATORS))
            : Unmapped::NoInfection;
    }

    /**
     * The names a gate ignore takes for every mutator Infection has.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->grouped(array_values($this->names));
    }

    /**
     * The names a gate ignore takes for every mutator a profile turns on, or why there are none.
     *
     * @return list<string>|Unmapped
     */
    public function of(string $profile): array|Unmapped
    {
        return array_key_exists($profile, $this->profiles)
            ? $this->grouped($this->mutators($profile))
            : Unmapped::NoProfile;
    }

    /**
     * The mutators a profile turns on, by name, through the profiles it names.
     *
     * @return list<string>
     */
    private function mutators(string $profile): array
    {
        $mutators = [];

        foreach ($this->profiles[$profile] as $entry) {
            $mutators = [
                ...$mutators,
                ...(str_starts_with($entry, '@') ? $this->mutators($entry) : [$this->name($entry)]),
            ];
        }

        return array_values(array_unique($mutators));
    }

    /** A mutator's name, by its class; a name already where the profile gives one. */
    private function name(string $mutator): string
    {
        return array_key_exists($mutator, $this->names) ? $this->names[$mutator] : $mutator;
    }

    /**
     * Each family whose every mutator of Infection's is in the set, then every other mutator in it by name.
     *
     * @param  list<string> $mutators
     * @return list<string>
     */
    private function grouped(array $mutators): array
    {
        $names = [];

        foreach (MutatorFamily::cases() as $family) {
            $members = array_values(array_filter(
                $this->names,
                static fn(string $name): bool => Families::of($name) === $family,
            ));
            $whole = $family !== MutatorFamily::None && $members !== [] && array_diff($members, $mutators) === [];
            $names = $whole ? [...$names, $family->value] : $names;
            $mutators = $whole ? array_values(array_diff($mutators, $members)) : $mutators;
        }

        return [...$names, ...$mutators];
    }
}
