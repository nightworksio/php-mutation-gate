<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_slice;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\LineSpan;
use NightWorksIO\MutationGate\Core\Mutant\MadeFile;
use NightWorksIO\MutationGate\Core\Mutant\MadeLines;

use function strval;

/**
 * The lines a shard's run made each file's mutants on, where it made some
 * on some lines alone (ADR-0193), as the shard's result holds them under
 * `madeLines`: by each file's path, its last line, then the spans made, each its
 * first and last line. A shard that made every file whole holds none.
 *
 * @internal the shape of a shard result's `madeLines`
 */
final readonly class MadeRecord
{
    public const string SECTION = 'madeLines';

    /**
     * The section of these lines, by its name, to spread into a result; nothing where every file was made whole.
     *
     * @return array<string, array<string, list<int|list<int>>>>
     */
    public static function of(MadeLines $made): array
    {
        $files = [];

        foreach ($made as $file) {
            $spans = [];

            foreach ($file as $span) {
                $spans[] = [$span->first()->number(), $span->last()->number()];
            }

            $files[$file->path()->value()] = [$file->last()->number(), ...$spans];
        }

        return $files === [] ? [] : [self::SECTION => $files];
    }

    /** The lines a section holds; every file whole where it is absent. */
    public static function read(Node $section): MadeLines
    {
        $made = MadeLines::whole();

        foreach ($section->isPresent() ? $section->entries() : [] as $path => $file) {
            $items = $file->items();
            $spans = [];

            foreach (array_slice($items, 1) as $span) {
                [$first, $last] = $span->items();
                $spans[] = LineSpan::of(Line::of($first->integer()), Line::of($last->integer()));
            }

            $made = $made->with(MadeFile::of(Path::of(strval($path)), Line::of($items[0]->integer()), ...$spans));
        }

        return $made;
    }
}
