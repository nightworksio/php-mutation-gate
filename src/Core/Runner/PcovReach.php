<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

use function preg_quote;
use function sprintf;

/**
 * Which files pcov collects a coverage run's lines from: every file under the
 * project's root but those in its vendor directory. Left unset, pcov collects
 * only from the first of `src`, `lib` and `app` in the directory it runs in,
 * so a tree beside it reads as run by no test. Xdebug reads neither setting.
 */
final readonly class PcovReach
{
    /** pcov's setting of the directory it collects from. */
    private const string DIRECTORY = 'pcov.directory';

    /** pcov's setting of the pattern a file it leaves out matches, its path as PHP compiled it. */
    private const string EXCLUDE = 'pcov.exclude';

    /** How PHP's command line sets an ini setting. */
    private const string SETS = '-d';

    private const string SETTING = '%s=%s';

    /** A path that starts in a directory. */
    private const string WITHIN = '~^%s/~';

    private const string DELIMITER = '~';

    private function __construct(private string $root, private string $vendor)
    {
    }

    /**
     * Under a project's root, by its real path, leaving out its vendor
     * directory, a path of the project, as PHP compiles a file in it: with
     * each `..` taken back.
     */
    public static function under(string $root, Path $vendor): self
    {
        return new self($root, Path::of(Root::of($root)->at($vendor)->value())->collapsed()->value());
    }

    /** @return list<string> the options of PHP's command line that set both */
    public function options(): array
    {
        $excluded = sprintf(self::WITHIN, preg_quote($this->vendor, self::DELIMITER));

        return [
            self::SETS,
            sprintf(self::SETTING, self::DIRECTORY, $this->root),
            self::SETS,
            sprintf(self::SETTING, self::EXCLUDE, $excluded),
        ];
    }
}
