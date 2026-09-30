<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\References;
use NightWorksIO\MutationGate\Core\Php\Site;
use NightWorksIO\MutationGate\Core\Php\Source;
use RuntimeException;

use function sprintf;

/** PHP files written in a test, read as the reference scan reads them. */
final class Php
{
    /** A source file, or a test file, of this code at a path. */
    public static function source(string $code, string $path = 'src/A.php', bool $test = false): Source
    {
        return Source::read(Path::of($path), Contents::of($code), $test);
    }

    /** Where the nth token written as this text stands among a file's tokens, from the first. */
    public static function indexOf(Source $source, string $text, int $nth = 0): int
    {
        $seen = 0;

        for ($at = 0; $at < $source->tokens()->count(); $at++) {
            if ($source->tokens()->text($at) === $text && $seen++ === $nth) {
                return $at;
            }
        }

        throw new RuntimeException(sprintf('The code writes no token %s.', $text));
    }

    /**
     * Each site of some references as `path:line`, a test's marked `(test)`.
     *
     * @return list<string>
     */
    public static function sites(References $references): array
    {
        return array_map(static fn(Site $site): string => sprintf(
            '%s:%d%s',
            $site->file()->value(),
            $site->line()->number(),
            $site->isInTest() ? ' (test)' : '',
        ), $references->sites());
    }
}
