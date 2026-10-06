<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_map;
use function glob;
use function implode;
use function mb_strlen;
use function mb_strpos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\ShellPattern;

use function sort;
use function strpbrk;

/**
 * The directories a `<directory>` of PHPUnit's `<source>` names, as PHPUnit
 * expands it (php-file-iterator's `Factory`): a path with a wildcard is each
 * directory it matches, where `**` matches any depth of directories, none
 * among them, and none where it matches nothing. A path with no wildcard is
 * itself.
 */
final readonly class DirectoryGlob
{
    /** One more level of directories, after the levels already matched. */
    private const string LEVEL = '/*';

    private const string ANY = '*';

    private function __construct(private Root $root)
    {
    }

    /** Patterns expanded from a project's root. */
    public static function from(Root $root): self
    {
        return new self($root);
    }

    /** The directories a path names: itself, or, where it holds a wildcard, each it matches, sorted. */
    public function expanded(Path $directory): Paths
    {
        if (strpbrk($directory->value(), ShellPattern::WILDCARDS) === false) {
            return Paths::of($directory);
        }

        $found = [];

        foreach ($this->matches($this->root->at($directory)->value()) as $match) {
            $found[] = $this->root->relative($match)->value();
        }

        sort($found);

        return Paths::of(...array_map(Path::of(...), $found));
    }

    /**
     * Every directory a pattern matches.
     *
     * @return list<string>
     */
    private function matches(string $pattern): array
    {
        $at = mb_strpos($pattern, Glob::DEEP);

        if ($at === false) {
            return $this->directories($pattern);
        }

        $found = [];

        $head = mb_substr($pattern, 0, $at);
        $tail = mb_substr($pattern, $at + mb_strlen(Glob::DEEP));

        foreach ($this->depths($head, $tail) as $deeper) {
            foreach ($this->matches($deeper) as $match) {
                $found[] = $match;
            }
        }

        return $found;
    }

    /**
     * The patterns `**` stands for between a head and a tail: the tail right
     * after the head, then after each directory under it, level by level.
     *
     * @return list<string>
     */
    private function depths(string $head, string $tail): array
    {
        $depths = [implode('', [$head, $tail])];
        $level = implode('', [$head, self::ANY]);
        $directories = $this->directories($level);

        while ($directories !== []) {
            foreach ($directories as $directory) {
                $depths[] = implode('', [$directory, $tail]);
            }

            $level = implode('', [$level, self::LEVEL]);
            $directories = $this->directories($level);
        }

        return $depths;
    }

    /**
     * The directories, and no file, a pattern without `**` matches.
     *
     * @return list<string>
     */
    private function directories(string $pattern): array
    {
        $found = glob($pattern, GLOB_ONLYDIR);

        return $found === false ? [] : $found;
    }
}
