<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_all;
use function array_filter;
use function array_first;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function basename;

use Closure;

use function count;
use function dirname;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sort;
use function sprintf;
use function str_contains;

/**
 * A patch of an installed package's source, as a command of the gate's
 * applies it from a project's `post-install-cmd` (ADR-0004): its hunks, each
 * marked with a comment that names the command. A runner adapter reads and
 * writes the package's files; this decides what they become.
 *
 * Every anchor is checked, against the source as the hunks before it left
 * it, before anything is written, so a moved one changes nothing, and
 * patching again finds the patch in place. A file that carries a hunk
 * another version of the gate wrote, which this one does not, is refused.
 */
final readonly class VendorPatch
{
    /** Where a hunk's text holds the mark. */
    public const string MARKED = '{MARK}';

    /** What begins the comment every hunk writes, and so finds every hunk another version of the gate wrote. */
    private const string MARK = '// mutation-gate %s:';

    private const string SOURCE = '%s/%s/src/%s';

    private const string PATCHED = '%s patched %d of the %d files it changes in %s.';

    /** Why the command cannot change a file another version of the gate patched. */
    private const string OTHER_VERSION
        = "%s patched nothing: %s holds another gate's patch. Run composer reinstall %s.";

    /** Why the command cannot change a file whose lines have moved. */
    private const string MOVED
        = '%s patched nothing: the lines it rewrites have moved in %s. Install a supported version.';

    /** Why the command cannot read a file it changes. */
    private const string UNREADABLE = '%s cannot read %s. Is %s installed?';

    /** Why the command cannot change a file it has to. */
    private const string UNWRITABLE = '%s cannot write %s/%s. Make the vendor directory writable.';

    /** @param list<Hunk> $hunks */
    private function __construct(private string $command, private string $package, private array $hunks)
    {
    }

    /** The patch a command applies to a package, by its Composer name, its hunks' text holding MARKED for the mark. */
    public static function of(string $command, string $package, Hunk ...$hunks): self
    {
        $mark = sprintf(self::MARK, $command);

        return new self(
            $command,
            $package,
            array_values(array_map(static fn(Hunk $hunk): Hunk => $hunk->marked(self::MARKED, $mark), $hunks)),
        );
    }

    /** The path of a file of the package's source, by its path under the source directory, or of the directory. */
    public function path(string $vendor, string $file = ''): string
    {
        return sprintf(self::SOURCE, $vendor, $this->package, $file);
    }

    /** @return list<string> each file a hunk changes, by its path under the source directory, once */
    public function files(): array
    {
        return array_values(array_unique(array_map(static fn(Hunk $hunk): string => $hunk->file(), $this->hunks)));
    }

    /** Why the command cannot read a file it changes. */
    public function unreadable(string $path): CannotJudge
    {
        return CannotJudge::because(sprintf(self::UNREADABLE, $this->command, $path, basename($this->package)));
    }

    /** Why the command could not write the files it changes. */
    public function unwritten(string $vendor): CannotJudge
    {
        return CannotJudge::because(
            sprintf(self::UNWRITABLE, $this->command, $vendor, sprintf('%s/src', $this->package)),
        );
    }

    /** What the command says once it has written this many files. */
    public function done(int $written): string
    {
        return sprintf(self::PATCHED, $this->command, $written, count($this->files()), basename($this->package));
    }

    /**
     * Whether the package carries every hunk, and no hunk another version wrote.
     *
     * @param array<string, string> $sources each file a hunk changes, by its path under the source directory
     * @param array<string, string> $every   every file of the package's source, by its path under the source directory
     */
    public function isAppliedTo(array $sources, array $every): bool
    {
        return $this->otherVersions($sources, $every) === []
            && array_all($this->hunks, static fn(Hunk $hunk): bool => $hunk->isAppliedTo($sources[$hunk->file()]));
    }

    /**
     * Each file's source with every hunk it lacks applied, of the files a
     * hunk changes that change; or why not: a file another version of the
     * gate patched, a line a hunk rewrites that has moved, or a file it
     * changes that cannot be written.
     *
     * @param  array<string, string>  $sources  each file a hunk changes, by its path under the source directory
     * @param  array<string, string>  $every    every file of the package's source, by its path under it
     * @param  Closure(string): bool  $writable whether the file at a path can be written
     * @return array<string, string>|CannotJudge
     */
    public function patched(string $vendor, array $sources, array $every, Closure $writable): array|CannotJudge
    {
        $other = $this->otherVersions($sources, $every);

        if ($other !== []) {
            return CannotJudge::because(
                sprintf(self::OTHER_VERSION, $this->command, $this->path($vendor, $other[0]), $this->package),
            );
        }

        $patched = $sources;

        foreach ($this->hunks as $hunk) {
            $source = $patched[$hunk->file()];

            if (! $hunk->isAppliedTo($source) && ! $hunk->fits($source)) {
                return CannotJudge::because(sprintf(self::MOVED, $this->command, $this->path($vendor, $hunk->file())));
            }

            $patched[$hunk->file()] = $hunk->isAppliedTo($source) ? $source : $hunk->applyTo($source);
        }

        return $this->writable($vendor, $patched, $sources, $writable);
    }

    /**
     * The files that change, where each can be written; or why one cannot.
     *
     * @param  array<string, string> $patched
     * @param  array<string, string> $sources
     * @param  Closure(string): bool $writable
     * @return array<string, string>|CannotJudge
     */
    private function writable(string $vendor, array $patched, array $sources, Closure $writable): array|CannotJudge
    {
        $changed = array_filter(
            $patched,
            static fn(string $source, string $file): bool => $source !== $sources[$file],
            ARRAY_FILTER_USE_BOTH,
        );
        $locked = array_values(array_filter(
            array_keys($changed),
            fn(string $file): bool => ! $writable($this->path($vendor, $file)),
        ));
        $first = $this->path($vendor, $locked === [] ? '' : array_first($locked));

        return $locked === []
            ? $changed
            : CannotJudge::because(sprintf(self::UNWRITABLE, $this->command, dirname($first), basename($first)));
    }

    /**
     * Every file of the package's source that carries a line another
     * version of the gate's command wrote which no hunk of this one writes:
     * a hunk written differently, or in a file this version leaves alone,
     * which patching again cannot take out.
     *
     * @param  array<string, string> $sources each file a hunk changes, by its path under the source directory
     * @param  array<string, string> $every   every file of the package's source, by its path under the source directory
     * @return list<string>
     */
    private function otherVersions(array $sources, array $every): array
    {
        $left = $sources;

        foreach ($this->hunks as $hunk) {
            $left[$hunk->file()] = $hunk->takenFrom($left[$hunk->file()]);
        }

        $mark = sprintf(self::MARK, $this->command);
        $marked = [];

        foreach ($every as $file => $text) {
            $source = array_key_exists($file, $left) ? $left[$file] : $text;
            $marked = str_contains($source, $mark) ? [...$marked, $file] : $marked;
        }

        sort($marked);

        return $marked;
    }
}
