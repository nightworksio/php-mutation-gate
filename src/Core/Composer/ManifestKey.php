<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

/**
 * The keys of a `composer.json` that how the gate runs reads apart (ADR-0056):
 * the lists that name packages, and those that only describe the package or
 * name its commands. Every other key decides how the project installs or
 * loads.
 *
 * @internal
 */
enum ManifestKey: string
{
    case Require = 'require';
    case RequireDev = 'require-dev';
    case Conflict = 'conflict';
    case Replace = 'replace';
    case Provide = 'provide';
    case Abandoned = 'abandoned';
    case Archive = 'archive';
    case Authors = 'authors';
    case Description = 'description';
    case Funding = 'funding';
    case Homepage = 'homepage';
    case Keywords = 'keywords';
    case License = 'license';
    case NonFeatureBranches = 'non-feature-branches';
    case Readme = 'readme';
    case Scripts = 'scripts';
    case ScriptsAliases = 'scripts-aliases';
    case ScriptsDescriptions = 'scripts-descriptions';
    case Suggest = 'suggest';
    case Support = 'support';
    case Time = 'time';

    /** Whether the key holds a list of packages, each with its constraint. */
    public function listsPackages(): bool
    {
        return match ($this) {
            self::Require, self::RequireDev, self::Conflict, self::Replace, self::Provide => true,
            self::Abandoned, self::Archive, self::Authors, self::Description, self::Funding, self::Homepage,
            self::Keywords, self::License, self::NonFeatureBranches, self::Readme, self::Scripts,
            self::ScriptsAliases, self::ScriptsDescriptions, self::Suggest, self::Support, self::Time => false,
        };
    }
}
