<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_key_exists;
use function intval;
use function mb_strpos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Files as a commit holds them, read from git in one `cat-file --batch`. A
 * file whose name holds a line feed or a carriage return, which the batch
 * reads a name to end at, is read on its own.
 */
final readonly class Blobs
{
    /** How the batch prints an object it found: its id, its type and its size in bytes. */
    private const string FOUND = '/^[0-9a-f]{40,64} (?<type>\S+) (?<size>\d+)$/D';

    private const string BLOB = 'blob';

    /** The batch prints bytes, and its sizes count them. */
    private const string BYTES = '8bit';

    private function __construct(private Command $git, private string $commit)
    {
    }

    /** The files of a commit, named by its full id. */
    public static function at(Command $git, string $commit): self
    {
        return new self($git, $commit);
    }

    /** @return ByPath<Contents|Missing>|CannotTell */
    public function of(Paths $paths): ByPath|CannotTell
    {
        $batched = [];
        $alone = [];

        foreach ($paths as $path) {
            if (! str_contains($path->value(), "\n") && ! str_contains($path->value(), "\r")) {
                $batched[] = $path;

                continue;
            }

            $alone[$path->value()] = $this->one($path);
        }

        $held = $this->batch($batched);

        return $held instanceof CannotTell ? $held : ByPath::mapping(
            $paths,
            static fn(Path $path): Contents|Missing => array_key_exists($path->value(), $alone)
                ? $alone[$path->value()]
                : $held->at($path, Missing::at($path)),
        );
    }

    /**
     * What a batch printed for each of these, in the order they were asked:
     * a blob's contents, and missing for a name it found nothing or no blob
     * at, or printed nothing for.
     *
     * @return ByPath<Contents|Missing>
     */
    public static function read(string $printed, Paths $paths): ByPath
    {
        $held = [];
        $offset = 0;

        foreach ($paths as $path) {
            [$held[$path->value()], $offset] = self::next($printed, $offset, $path);
        }

        return ByPath::mapping($paths, static fn(Path $path): Contents|Missing => $held[$path->value()]);
    }

    /**
     * These files, read in one batch.
     *
     * @param  list<Path>                         $paths
     * @return ByPath<Contents|Missing>|CannotTell
     */
    private function batch(array $paths): ByPath|CannotTell
    {
        $asked = '';

        foreach ($paths as $path) {
            $asked .= sprintf("%s\n", $this->spelt($path));
        }

        $printed = $asked === '' ? '' : $this->git->feed(['cat-file', '--batch'], $asked);

        return $printed instanceof CannotTell ? $printed : self::read($printed, Paths::of(...$paths));
    }

    /**
     * The file the batch printed from an offset, and the offset after it.
     *
     * @return array{Contents|Missing, int}
     */
    private static function next(string $printed, int $offset, Path $path): array
    {
        $end = mb_strpos($printed, "\n", $offset, self::BYTES);
        $header = $end === false ? '' : mb_substr($printed, $offset, $end - $offset, self::BYTES);

        if ($end === false || preg_match(self::FOUND, $header, $found) !== 1) {
            return [Missing::at($path), $end === false ? $offset : $end + 1];
        }

        $size = intval($found['size']);
        $file = $found['type'] === self::BLOB
            ? Contents::of(mb_substr($printed, $end + 1, $size, self::BYTES))
            : Missing::at($path);

        return [$file, $end + 1 + $size + 1];
    }

    /** A file read on its own. */
    private function one(Path $path): Contents|Missing
    {
        $blob = $this->git->run(['cat-file', 'blob', $this->spelt($path)]);

        return $blob instanceof CannotTell ? Missing::at($path) : Contents::of($blob);
    }

    /** A file at the commit, spelt from the directory git runs in. */
    private function spelt(Path $path): string
    {
        return sprintf('%s:./%s', $this->commit, $path->value());
    }
}
