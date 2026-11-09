<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_last;
use function array_map;

use Closure;

use function count;
use function explode;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Looked;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;

use function str_starts_with;
use function strval;

/**
 * A look at the working tree through `git status` (ADR-0120): git answers
 * from its index's stat cache which paths differ from the commit, tracked or
 * not, and only those, and those that differed at the last look, are hashed
 * again; every other file holds what the commit holds, as it did then. A look
 * at another commit than the last, or after none, lists and hashes every
 * file. It takes no lock on the index, so it never stands in the way of the
 * user's own git commands.
 *
 * @internal
 */
final readonly class StatusLook
{
    /** The header that names the commit the working tree is checked out at. */
    private const string COMMIT = '# branch.oid ';

    /** How many fields go between an entry's kind and its path, by its kind. */
    private const array FIELDS = ['1' => 7, '2' => 8, 'u' => 9, '?' => 0];

    /** How an entry begins that names, in the entry after it, the path it was renamed from. */
    private const string RENAMED = '2 ';

    private function __construct(private Command $git, private WorkingTree $tree)
    {
    }

    public static function of(Command $git, WorkingTree $tree): self
    {
        return new self($git, $tree);
    }

    /**
     * The files now, given the last look; or the whole look where git names
     * another commit, or no commit, than that look was taken at.
     *
     * @param Closure(): (Fingerprints|CannotTell) $whole
     */
    public function since(Looked $then, Closure $whole): Looked|CannotTell
    {
        $status = $this->git->run([
            '--no-optional-locks',
            'status',
            '--porcelain=v2',
            '--branch',
            '-z',
            '--untracked-files=all',
            '--',
            '.',
            ...OutsideTheWorkspace::pathspec(),
        ]);

        if ($status instanceof CannotTell) {
            return $status;
        }

        [$commit, $differing] = $this->read($status);

        if (! $then->isAt($commit)) {
            $files = $whole();

            return $files instanceof CannotTell ? $files : Looked::at($files, $commit, $differing);
        }

        $again = [...$this->keyed($differing), ...$this->keyed($then->differing())];
        $hashed = $this->tree->fingerprints(array_map(strval(...), array_keys($again)));

        return $hashed instanceof CannotTell
            ? $hashed
            : Looked::at($this->overlaid($then->files(), $again, $hashed), $commit, $differing);
    }

    /**
     * The last look's files, those hashed again replaced by what they hold
     * now, or left out where they are gone.
     *
     * @param array<string, true> $again
     */
    private function overlaid(Fingerprints $then, array $again, Fingerprints $hashed): Fingerprints
    {
        $kept = array_filter(
            [...$then],
            static fn(Fingerprint $file): bool => ! array_key_exists($file->path()->value(), $again),
        );

        return Fingerprints::of(...$kept, ...$hashed);
    }

    /**
     * The commit git names, and every path an entry names: both of a rename,
     * whose entry is followed by the path it was renamed from.
     *
     * @return array{string, list<string>}
     */
    private function read(string $status): array
    {
        $commit = '';
        $paths = [];
        $origin = false;

        foreach (Diff::paths($status) as $entry) {
            $named = $origin ? [$entry] : $this->named($entry);
            $origin = ! $origin && str_starts_with($entry, self::RENAMED);
            $commit = str_starts_with($entry, self::COMMIT) ? mb_substr($entry, mb_strlen(self::COMMIT)) : $commit;
            $paths = [...$paths, ...$named];
        }

        return [$commit, $paths];
    }

    /**
     * The path an entry names; none for a header.
     *
     * @return list<string>
     */
    private function named(string $entry): array
    {
        $parts = explode(' ', $entry, 2);

        if (count($parts) !== 2 || ! array_key_exists($parts[0], self::FIELDS)) {
            return [];
        }

        $fields = self::FIELDS[$parts[0]];
        $split = explode(' ', $parts[1], $fields + 1);

        return count($split) === $fields + 1 ? [array_last($split)] : [];
    }

    /**
     * @param  list<string>        $paths
     * @return array<string, true>
     */
    private function keyed(array $paths): array
    {
        $keyed = [];

        foreach ($paths as $path) {
            $keyed[$path] = true;
        }

        return $keyed;
    }
}
