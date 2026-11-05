<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function array_slice;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Heat\FileHeat;
use NightWorksIO\MutationGate\Core\Heat\Heat;
use NightWorksIO\MutationGate\Core\Score\Score;

use function sprintf;

/**
 * The pull request comment's heatmap (ADR-0084): the files the change
 * reaches that fell most since the base, then any below its tree's floor,
 * as a table, each with its score, its change against the base, and its
 * lowest-scoring functions inline with theirs.
 *
 * @internal
 */
final readonly class HeatmapMarkdown
{
    /** How many of a file's functions its row names, the lowest-scoring. */
    public const int FUNCTIONS = 3;

    private const array HEAD = ['| File | Score | Against the base | Lowest functions |', '|---|---|---|---|'];

    private const string ROW = '| %s | %s | %s | %s |';

    private const string CHANGED = '%s (%s)';

    /**
     * The table of these files; nothing where there are none.
     *
     * @param  list<FileHeat> $files
     * @return list<string>
     */
    public static function table(array $files): array
    {
        return $files === [] ? [] : [implode("\n", [...self::HEAD, ...array_map(self::row(...), $files)])];
    }

    private static function row(FileHeat $file): string
    {
        $functions = array_map(
            static fn(Heat $function): string => sprintf(
                Fit::JOINED,
                Escape::code($function->name()),
                self::scored($function),
            ),
            array_slice($file->functions(), 0, self::FUNCTIONS),
        );

        return sprintf(
            self::ROW,
            Escape::code($file->path()->value()),
            Percent::of($file->heat()->score()),
            self::change($file->heat()),
            implode(', ', $functions),
        );
    }

    /** A function's score, with its change against the base where it has one. */
    private static function scored(Heat $heat): string
    {
        $change = self::change($heat);

        $score = Percent::of($heat->score());

        return $change === '' ? $score : sprintf(self::CHANGED, $score, $change);
    }

    /** A score's change against the base; nothing where either has no score. */
    private static function change(Heat $heat): string
    {
        $score = $heat->score();
        $base = $heat->base();

        return $score instanceof Score && $base instanceof Score ? Percent::change($base, $score) : '';
    }
}
