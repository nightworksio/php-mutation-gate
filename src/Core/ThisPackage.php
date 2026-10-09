<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/** The gate itself, as Composer installs it and as a project names it. */
final readonly class ThisPackage
{
    /** Its Composer package. */
    public const string COMPOSER = 'nightworksio/mutation-gate';

    /** Its short name: its command, its config file, and its key under a manifest's `extra`. */
    public const string NAME = 'mutation-gate';

    /** Its repository, which the pre-commit framework clones its hooks from. */
    public const string REPOSITORY = 'https://github.com/nightworksio/php-mutation-gate';
}
