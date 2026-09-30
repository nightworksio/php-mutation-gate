<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;

/**
 * The names PHPUnit's config has, in the order PHPUnit looks for them in a
 * directory: it reads the first it finds. Both runners run PHPUnit with it.
 * Infection, looking for itself, tries `phpunit.xml.dist` before
 * `phpunit.dist.xml`.
 */
enum PhpUnitConfig: string
{
    case Xml = 'phpunit.xml';
    case DistXml = 'phpunit.dist.xml';
    case XmlDist = 'phpunit.xml.dist';

    /** Where each name would be in a directory, in PHPUnit's order. */
    public static function candidatesIn(Path $directory): Paths
    {
        return Paths::of(...array_map(static fn(self $config): Path => $config->in($directory), self::cases()));
    }

    /** Where the config of this name would be in a directory. */
    public function in(Path $directory): Path
    {
        return Path::of(sprintf('%s/%s', $directory->value(), $this->value));
    }
}
