<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function in_array;
use function mb_strlen;
use function mb_strrpos;
use function mb_substr;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * The keys of a manifest's `autoload` whose values are paths, in the order
 * the gate reads them. `exclude-from-classmap` names paths too, but to leave
 * them out, so it names no tree.
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
    public function fileOf(string $class, string $prefix): string
    {
        if (! str_starts_with($class, $prefix) || ! in_array($this, [self::Psr4, self::Psr0], strict: true)) {
            return '';
        }

        $name = $this === self::Psr4 ? mb_substr($class, mb_strlen($prefix)) : $class;
        $at = mb_strrpos($name, '\\');
        $namespace = $at === false ? '' : mb_substr($name, 0, $at + 1);
        $short = $at === false ? $name : mb_substr($name, $at + 1);
        $short = $this === self::Psr0 ? str_replace('_', '/', $short) : $short;

        return sprintf('%s%s.php', str_replace('\\', '/', $namespace), $short);
    }
}
