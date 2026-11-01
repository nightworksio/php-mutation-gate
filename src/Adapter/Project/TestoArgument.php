<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

/**
 * Each argument of Testo's config classes the gate reads from a testo.php,
 * by its name and by its position in its constructor: a `SuiteConfig`'s
 * location, a `FinderConfig`'s paths it includes and leaves out, and an
 * `ApplicationConfig`'s suites.
 */
enum TestoArgument: string
{
    case Location = 'location';
    case Include = 'include';
    case Exclude = 'exclude';
    case Suites = 'suites';

    public function position(): int
    {
        return match ($this) {
            self::Include => 0,
            self::Location, self::Exclude, self::Suites => 1,
        };
    }
}
