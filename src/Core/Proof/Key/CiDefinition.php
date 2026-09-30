<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_filter;
use function array_map;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

use function preg_match;
use function preg_replace;

/**
 * A CI definition that runs the gate, read as it runs: without its comments,
 * its blank lines, and the commit an action is pinned at. A pin that moves or
 * a comment that changes is not a change to how a mutant runs.
 */
final readonly class CiDefinition
{
    /** A line that holds nothing but a comment, or nothing at all. */
    private const string SILENT = '/^\s*(?:#.*)?$/u';

    /** An action pinned at a commit, `uses: owner/repo@<sha>`, and the comment that names its tag. */
    private const string PIN = '/^(\s*(?:-\s+)?uses:\s*[^@\s]+)@[0-9a-f]{40}(?:\s+#.*)?$/u';

    private function __construct(private Path $path, private string $asItRuns)
    {
    }

    public static function at(Path $path, Contents $contents): self
    {
        $lines = array_filter(
            explode("\n", $contents->text()),
            static fn(string $line): bool => preg_match(self::SILENT, $line) !== 1,
        );
        $unpinned = array_map(
            static fn(string $line): string => preg_replace(self::PIN, '$1', $line) ?? $line,
            $lines,
        );

        return new self($path, implode("\n", $unpinned));
    }

    public function path(): Path
    {
        return $this->path;
    }

    public function asItRuns(): string
    {
        return $this->asItRuns;
    }
}
