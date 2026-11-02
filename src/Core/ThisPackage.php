<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

use function dirname;
use function sprintf;

/**
 * The gate itself, as Composer installs it and as a project names it.
 *
 * @internal
 */
final readonly class ThisPackage
{
    /** Its Composer package. */
    public const string COMPOSER = 'nightworksio/mutation-gate';

    /** Its short name: its command, its config file, and its key under a manifest's `extra`. */
    public const string NAME = 'mutation-gate';

    /** Its repository, which the pre-commit framework clones its hooks from. */
    public const string REPOSITORY = 'https://github.com/nightworksio/php-mutation-gate';

    /** Its documentation site, whose root holds `main`'s pages while no version is tagged (ADR-0038). */
    public const string SITE = 'https://nightworksio.github.io/php-mutation-gate';

    /** Where each of its JSON Schemas is published, by its name, at the tag of its release line. */
    public const string SCHEMA
        = 'https://raw.githubusercontent.com/nightworksio/php-mutation-gate/v0.1/resources/%s.schema.json';

    /** Where its scripts are, in its own directory: two up from this file. */
    private const string SCRIPT = '%s/bin/%s';

    private const int OWN = 2;

    /** One of its scripts, by its absolute path, wherever Composer installed it. */
    public static function script(string $name): string
    {
        return sprintf(self::SCRIPT, dirname(__DIR__, self::OWN), $name);
    }
}
