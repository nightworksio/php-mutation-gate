<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_pop;
use function explode;
use function implode;
use function in_array;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * The keys of a manifest's `autoload` whose values are paths, in the order
 * the gate reads them. `exclude-from-classmap` names paths too, but to leave
 * them out, so it names no tree.
 *
 * @internal
 */
enum AutoloadKind: string
{
    case Psr4 = 'psr-4';
    case Psr0 = 'psr-0';
    case Classmap = 'classmap';
    case Files = 'files';

    /**
     * The file below a directory of this kind's that a prefix maps a class
     * to; none where the prefix is not the class's, or the kind maps no
     * class by its name. psr-4 takes the prefix off; psr-0 keeps it, and
     * each `_` of the short name is one more directory.
     */
    public function fileOf(string $class, string $prefix): Paths
    {
        if (! str_starts_with($class, $prefix) || ! in_array($this, [self::Psr4, self::Psr0], strict: true)) {
            return Paths::none();
        }

        $name = $this === self::Psr4 ? mb_substr($class, mb_strlen($prefix)) : $class;
        $segments = explode('\\', $name);
        $short = array_pop($segments);
        $short = $this === self::Psr0 ? str_replace('_', '/', $short) : $short;

        return Paths::of(Path::of(sprintf('%s.php', implode('/', [...$segments, $short]))));
    }

    /**
     * The directory below a directory of this kind's that a prefix maps a
     * namespace to, `.` for the directory itself; none where the prefix is
     * not the namespace's, or the kind maps no class by its name.
     */
    public function directoryOf(string $namespace, string $prefix): Paths
    {
        $namespace = sprintf('%s\\', trim($namespace, '\\'));

        if (! str_starts_with($namespace, $prefix) || ! in_array($this, [self::Psr4, self::Psr0], strict: true)) {
            return Paths::none();
        }

        $name = $this === self::Psr4 ? mb_substr($namespace, mb_strlen($prefix)) : $namespace;

        return Paths::of(Path::of($name));
    }
}
