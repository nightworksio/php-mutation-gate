<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_any;
use function array_key_exists;
use function array_map;
use function array_values;
use function basename;
use function count;
use function explode;

use NightWorksIO\MutationGate\Core\File\Path;

use function pathinfo;

use const PATHINFO_FILENAME;

use function sprintf;

/**
 * Files, each with the files it names: those that declare a name it
 * mentions. The one name graph: the walk from some files to every file they
 * need, in turn, that the proof key's support and a mutant's narrowed run
 * both take, and the walk back from some names to every file that needs
 * them, that a kill carried across commits takes.
 */
final readonly class NamedFiles
{
    /** How a constant is named, kept apart from a class or function of the same spelling. */
    private const string CONSTANT = 'const %s';

    /** How a file that is not PHP is named, by a word of its name, kept apart from a class or function. */
    private const string FILE = 'file %s';

    /**
     * @param array<string, list<string>> $naming   the files each file names, by its path
     * @param array<string, list<string>> $mentions the names each file mentions, by its path
     */
    private function __construct(private array $naming, private array $mentions)
    {
    }

    /**
     * Files linked by name: each to every file that declares a name it
     * mentions, each once, in the order it mentions them.
     *
     * @param array<string, list<string>> $declares the names each file declares, by its path
     * @param array<string, list<string>> $mentions the names each file of the graph mentions, by its path
     */
    public static function byName(array $declares, array $mentions): self
    {
        $declaring = [];

        foreach ($declares as $file => $names) {
            foreach ($names as $name) {
                $declaring[$name][] = $file;
            }
        }

        $naming = [];

        foreach ($mentions as $file => $names) {
            $naming[$file] = self::declaringAny($names, $declaring);
        }

        return new self($naming, $mentions);
    }

    /**
     * PHP files linked by the names they resolve: a class, trait, enum or
     * function by its full name, spelt in code or as a fully qualified name in
     * a string, and a constant by its last segment, as PHP falls back to a
     * global one. Each also mentions every word its strings spell, by which it
     * names a file that is not PHP ({@see nameOf()}).
     *
     * @param array<string, PhpFile> $files each file, read, by its path
     */
    public static function read(array $files): self
    {
        $declares = [];
        $mentions = [];

        foreach ($files as $file => $read) {
            $mentioned = $read->mentioned()->all();
            $declares[$file] = self::declaredIn($read);
            $mentions[$file] = [
                ...$mentioned,
                ...$read->quoted()->all(),
                ...self::tagged(self::CONSTANT, array_map(self::lastSegment(...), $mentioned)),
                ...self::tagged(self::FILE, $read->words()->all()),
            ];
        }

        return self::byName($declares, $mentions);
    }

    /**
     * The names a PHP file declares, as {@see read()} links files by them.
     *
     * @return list<string>
     */
    public static function declaredIn(PhpFile $file): array
    {
        return [...$file->declares()->all(), ...self::tagged(self::CONSTANT, $file->constants()->all())];
    }

    /**
     * The names a file that is not PHP goes by, as {@see read()} links files
     * by them: each word of its name, less its extension, as a test that reads
     * `fixtures/rates.json` spells `rates`.
     *
     * @return list<string>
     */
    public static function nameOf(Path $file): array
    {
        return self::tagged(self::FILE, Words::in(pathinfo(basename($file->value()), PATHINFO_FILENAME))->all());
    }

    /** What each file of the graph reaches by the names it mentions, worked out once for the whole graph. */
    public function reaches(): ReachedFiles
    {
        return ReachedFiles::over($this->naming);
    }

    /** @return list<string> these files, and every file they name, transitively, each once, in the order reached */
    public function reachedFrom(string ...$from): array
    {
        return $this->walk($this->naming, $from);
    }

    /**
     * Every file that mentions one of these names, and every file that names
     * such a file, transitively: every file a change to what declares them can
     * reach, each once, in the order reached.
     *
     * @return list<string>
     */
    public function naming(string ...$names): array
    {
        $namedBy = [];

        foreach ($this->naming as $file => $named) {
            foreach ($named as $declaring) {
                $namedBy[$declaring][] = $file;
            }
        }

        return $this->walk($namedBy, $this->mentioning(...$names));
    }

    /**
     * Every file that mentions one of these names itself: the files a change
     * to what declares them can break directly, in the order of the graph.
     *
     * @return list<string>
     */
    public function mentioning(string ...$names): array
    {
        $asked = [];

        foreach ($names as $name) {
            $asked[$name] = true;
        }

        $mentioning = [];

        foreach ($this->mentions as $file => $mentioned) {
            if (array_any($mentioned, static fn(string $name): bool => array_key_exists($name, $asked))) {
                $mentioning[] = $file;
            }
        }

        return $mentioning;
    }

    /**
     * These files, and every file an edge leads to from them, transitively,
     * each once, in the order reached.
     *
     * @param  array<string, list<string>> $edges where each file leads, by its path
     * @param  array<string>               $from
     * @return list<string>
     */
    private function walk(array $edges, array $from): array
    {
        $reached = [];

        foreach ($from as $file) {
            $reached[$file] = $file;
        }

        $queue = array_values($reached);
        $at = 0;

        while ($at < count($queue)) {
            foreach (array_key_exists($queue[$at], $edges) ? $edges[$queue[$at]] : [] as $next) {
                if (! array_key_exists($next, $reached)) {
                    $reached[$next] = $next;
                    $queue[] = $next;
                }
            }

            $at++;
        }

        return array_values($reached);
    }

    /**
     * The files that declare any of these names, each once, in the order the
     * names come.
     *
     * @param  list<string>                $names
     * @param  array<string, list<string>> $declaring the files that declare each name, by the name
     * @return list<string>
     */
    private static function declaringAny(array $names, array $declaring): array
    {
        $files = [];

        foreach ($names as $name) {
            foreach (array_key_exists($name, $declaring) ? $declaring[$name] : [] as $file) {
                $files[$file] = $file;
            }
        }

        return array_values($files);
    }

    /**
     * @param  list<string> $names
     * @return list<string>
     */
    private static function tagged(string $tag, array $names): array
    {
        return array_map(static fn(string $name): string => sprintf($tag, $name), $names);
    }

    /** A name without its namespace. */
    private static function lastSegment(string $name): string
    {
        $segments = explode('\\', $name);

        return $segments[count($segments) - 1];
    }
}
