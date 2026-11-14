<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

use function preg_quote;
use function sprintf;

/**
 * Which files pcov collects a coverage run's lines from: every file under the
 * project's root but those in its vendor directory, save a directory in it
 * where the run serves copies of the project's own files. Left unset, pcov
 * collects only from the first of `src`, `lib` and `app` in the directory it
 * runs in, so a tree beside it reads as run by no test. Xdebug reads neither
 * setting.
 *
 * @internal
 */
final readonly class PcovReach
{
    public const string SETTING = '%s=%s';

    /** How PHP's command line sets an ini setting. */
    public const string SETS = '-d';
    /** pcov's setting of the directory it collects from. */
    private const string DIRECTORY = 'pcov.directory';

    /** pcov's setting of the pattern a file it leaves out matches, its path as PHP compiled it. */
    private const string EXCLUDE = 'pcov.exclude';

    /** A path that starts in a directory. */
    private const string WITHIN = '~^%s/~';

    /** A path that starts in a directory, but not in a directory within it. */
    private const string WITHIN_BUT = '~^%s/(?!%s/)~';

    private const string DELIMITER = '~';

    private function __construct(private string $root, private string $vendor, private string $kept = '')
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

    /** These, collecting from this directory of the vendor directory too, by its path from there. */
    public function keeping(Path $inVendor): self
    {
        return new self($this->root, $this->vendor, $inVendor->collapsed()->value());
    }

    /** @return list<string> the options of PHP's command line that set both */
    public function options(): array
    {
        $vendor = preg_quote($this->vendor, self::DELIMITER);
        $excluded = $this->kept === ''
            ? sprintf(self::WITHIN, $vendor)
            : sprintf(self::WITHIN_BUT, $vendor, preg_quote($this->kept, self::DELIMITER));

        return [
            self::SETS,
            sprintf(self::SETTING, self::DIRECTORY, $this->root),
            self::SETS,
            sprintf(self::SETTING, self::EXCLUDE, $excluded),
        ];
    }
}
