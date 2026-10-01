<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;
use function str_ends_with;
use function trim;

/**
 * The map php-code-coverage writes with `--coverage-php`, which a runner has
 * PHPUnit write into the directory the gate gives it and the gate reads back:
 * PHP that returns the serialized map, whole only where it ends as
 * php-code-coverage ends it.
 */
final readonly class PhpReport
{
    public const string NAME = 'coverage.php';

    /** How php-code-coverage ends a map it wrote whole. */
    private const string END = "END_OF_COVERAGE_SERIALIZATION\n);";

    private const string MISSING = 'There is no coverage map at %s, so no test runs any line.';

    private const string CUT_OFF = '%s cannot be read as a coverage map: it ends before the map does.';

    private const string UNREADABLE = '%s cannot be read as a coverage map: %s';

    /** Whether a map's text is whole: a run cut short leaves a map that reading, which runs it, would fail on. */
    public static function isWhole(string $text): bool
    {
        return str_ends_with(trim($text), self::END);
    }

    public static function missingAt(string $file): CannotJudge
    {
        return CannotJudge::because(sprintf(self::MISSING, $file));
    }

    public static function cutOffAt(string $file): CannotJudge
    {
        return CannotJudge::because(sprintf(self::CUT_OFF, $file));
    }

    /** A map php-code-coverage refused to read, with what it said. */
    public static function unreadableAt(string $file, string $why): CannotJudge
    {
        return CannotJudge::because(sprintf(self::UNREADABLE, $file, $why));
    }
}
