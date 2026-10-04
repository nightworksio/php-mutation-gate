<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use function addcslashes;
use function dirname;
use function explode;
use function implode;
use function ltrim;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * A program's optimized opcodes, as opcache dumps them, with what tells two
 * copies of one program apart and nothing else taken out (ADR-0013,
 * decision 10): the line each function spans, the line in each closure's
 * name, and the path the program was compiled at, wherever the program
 * names its own file or directory. A literal the program writes is kept as
 * it is, so a mutant that changes one never compares equal.
 */
final readonly class Opcodes
{
    /**
     * What the program's own path is named in its place: with a control
     * character, which the dump escapes in every literal, so that no literal
     * reads as it.
     */
    public const string FILE = "\x1Efile\x1E";

    /** What the program's own directory is named in its place, as its path is. */
    public const string DIRECTORY = "\x1Edirectory\x1E";
    /** The header opcache dumps a program's own code under, on a line of its own before any function's. */
    private const string MAIN = "\$_main:\n";

    /** A function's header names a closure with the line it starts on, each closure inside the one around it. */
    private const string CLOSURE_HEADER = '/^\{closure:.*\}:$/';

    private const string CLOSURE_LINE = '/:\d+\}/';

    private function __construct(private string $text)
    {
    }

    /**
     * The opcodes opcache dumped of the program it compiled at a path; or
     * that what was dumped is not a dump, which does not begin with the
     * program's own code, and proves nothing.
     */
    public static function dumped(string $dump, string $path): self|Uncompiled
    {
        if (! str_starts_with(ltrim($dump), self::MAIN)) {
            return Uncompiled::Failed;
        }

        $spans = sprintf('; %s:', $path);
        $kept = [];

        foreach (explode("\n", $dump) as $line) {
            if (str_starts_with(ltrim($line), $spans)) {
                continue;
            }

            $line = preg_match(self::CLOSURE_HEADER, $line) === 1
                ? (string) preg_replace(self::CLOSURE_LINE, '}', $line)
                : $line;
            $kept[] = self::named($line, $path);
        }

        return new self(implode("\n", $kept));
    }

    /** Whether another program compiles to these opcodes. */
    public function same(self $other): bool
    {
        return $this->text === $other->text;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** A line, with the program's own path and directory, as written and as the dump escapes them, named alike. */
    private static function named(string $line, string $path): string
    {
        $directory = dirname($path);

        return str_replace(
            [$path, addcslashes($path, '\\"'), $directory, addcslashes($directory, '\\"')],
            [self::FILE, self::FILE, self::DIRECTORY, self::DIRECTORY],
            $line,
        );
    }
}
