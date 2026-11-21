<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ChangeTable;
use NightWorksIO\MutationGate\Adapter\Pest\ChangeTableMade;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Identities;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\SideBySideMutants;
use NightWorksIO\MutationGate\Adapter\Pest\UnplacedFiles;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\LineSpan;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\MadeFile;
use NightWorksIO\MutationGate\Core\Mutant\MadeLines;
use NightWorksIO\MutationGate\Core\Mutant\MergedParts;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation as GateMutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Cache\NullStore;
use Pest\Mutate\Mutation;
use Pest\Mutate\Mutators\Sets\DefaultSet;
use Pest\Mutate\Support\MutationGenerator;
use Pest\Support\Container;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function (): void {
    GateEnvironment::clear();
    Container::getInstance()->add(CacheInterface::class, new NullStore());
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});

/**
 * A file that repeats its changes: one line three times, once indented
 * otherwise, an array whose items stand on lines of their own, and a call
 * that spans lines, twice.
 */
function repeatedSource(): string
{
    return <<<'PHP'
        <?php

        final class Packs
        {
            public static function one(string $text): string
            {
                $packed = gzencode($text);
                return is_string($packed) ? $packed : throw new RuntimeException();
            }

            public static function two(string $text): string
            {
                $packed = gzencode($text);
                    return is_string($packed) ? $packed : throw new RuntimeException();
            }

            public static function three(string $text): string
            {
                $packed = gzencode($text);
                return is_string($packed) ? $packed : throw new RuntimeException();
            }

            public static function items(): array
            {
                return [
                    1,
                    1,
                ];
            }

            public static function least(int $level): int
            {
                return max(
                    $level,
                    1,
                );
            }

            public static function most(int $level): int
            {
                return max(
                    $level,
                    1,
                );
            }
        }

        PHP;
}

/** The file, written under a project's root, as Pest finds it. */
function repeatedFile(string $root): SplFileInfo
{
    Scratch::write($root, 'src/Packs.php', repeatedSource());

    return new SplFileInfo(sprintf('%s/src/Packs.php', $root), 'src', 'src/Packs.php');
}

/** @return Closure(SplFileInfo, list<int>): array<int, Mutation> Pest's own making of a file's mutants */
function repeatedMaking(): Closure
{
    $generator = new MutationGenerator();

    return static fn(SplFileInfo $file, array $lines): array => $generator->generate(
        $file,
        DefaultSet::mutators(),
        array_values(array_filter($lines, is_int(...))),
    );
}

/**
 * Each mutant's gate id, by Pest's id and its lines, as a run that made
 * these of the file numbers them.
 *
 * @param  array<int, Mutation>    $made
 * @return array<string, MutantId>
 */
function repeatedIds(string $root, array $made, bool $placed): array
{
    $planned = PlannedMutant::inOrder(array_map(
        static fn(Mutation $mutant): PlannedMutant => $placed
            ? Recorder::madeOf($mutant)
            : PlannedMutant::of(
                $mutant->id,
                DiskPath::of(strval($mutant->file->getRealPath())),
                Line::of($mutant->startLine),
                Line::of($mutant->endLine),
                $mutant->mutator,
                $mutant->diff,
                DiskPath::of($mutant->modifiedSourcePath),
            ),
        array_values($made),
    ));
    $ids = [];

    foreach (Identities::of(Root::of($root), $planned) as $at => $id) {
        $ids[sprintf('%s:%d:%d', $planned[$at]->id(), $planned[$at]->start()->number(), $planned[$at]->end()->number())] = $id;
    }

    return $ids;
}

/**
 * The plan's table of the file's change places, made as the plan's run makes
 * it, and told to every run after it.
 */
function repeatedTable(string $root, SplFileInfo $file): void
{
    Scratch::write($root, '.mutation-gate/change-places.files', strval($file->getRealPath()));
    putenv(sprintf('%s=%s/.mutation-gate/change-places.files', GateVariable::Tabling->value, $root));
    putenv(sprintf('%s=%s/.mutation-gate/%s', GateVariable::Places->value, $root, ChangeTable::FILE));
    ChangeTableMade::tabled(repeatedMaking());
    putenv(GateVariable::Tabling->value);
}

function repeatedProject(string $root): Project
{
    return Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
}

function repeatedResultsOf(string $root): string
{
    return sprintf('%s/.mutation-gate/pest/results.jsonl', $root);
}

/** A run that records its results beside the plan's table. */
function repeatedResults(string $root): void
{
    Scratch::write($root, '.mutation-gate/pest/results.jsonl', '');
    putenv(sprintf('%s=%s', GateVariable::Results->value, repeatedResultsOf($root)));
}

/**
 * The files a run noted it made whole to number its mutants, each once.
 *
 * @return list<string>
 */
function repeatedUnplaced(string $root): array
{
    $note = sprintf('%s.unplaced', repeatedResultsOf($root));
    $text = is_file($note) ? (string) file_get_contents($note) : '';

    return array_values(array_unique(array_filter(explode("\n", $text), static fn(string $line): bool => $line !== '')));
}

/**
 * The file's lines cut into parts by a seed: each line to one part.
 *
 * @return list<list<int>>
 */
function repeatedSplit(int $seed, int $parts, int $lines): array
{
    mt_srand($seed);
    $split = array_fill(0, $parts, []);

    for ($line = 1; $line <= $lines; $line++) {
        $split[mt_rand(0, $parts - 1)][] = $line;
    }

    return array_values(array_filter($split, static fn(array $part): bool => $part !== []));
}

it('numbers each mutant of a file whose changes repeat as a run that makes every one does, however its lines are cut into parts and whichever a share makes, placed by either source', function (int $seed, int $parts, bool $tabled): void {
    $root = Scratch::directory();
    $file = repeatedFile($root);
    $making = repeatedMaking();
    $whole = repeatedIds($root, $making($file, []), placed: false);
    $count = substr_count(repeatedSource(), "\n");
    $tabled ? repeatedTable($root, $file) : null;
    repeatedResults($root);
    $merged = [];
    $differing = [];

    foreach (repeatedSplit($seed, $parts, $count) as $lines) {
        $made = SideBySideMutants::made([$file], static fn(): array => $lines, $making, cut: true)->of($file, []);
        $shared = array_values(array_filter($made, static fn(Mutation $mutant): bool => crc32($mutant->id) % 2 === $seed % 2));

        foreach ([repeatedIds($root, $made, placed: true), repeatedIds($root, $shared, placed: true)] as $ids) {
            foreach ($ids as $spot => $id) {
                $differing = $whole[$spot]->value() === $id->value() ? $differing : [...$differing, $spot];
            }
        }

        $merged[] = [repeatedMutants($made, repeatedIds($root, $made, placed: true)), repeatedMade($count, $lines)];
    }

    $judged = MergedParts::made(Path::of('src/Packs.php'), $merged);

    expect($differing)->toBe([])
        ->and($judged instanceof CannotJudge ? $judged->why() : count($judged))->toBe(count($whole))
        ->and(repeatedUnplaced($root))->toBe($tabled ? [] : [strval($file->getRealPath())]);
})->with([
    'two parts' => [1, 2],
    'three parts' => [2, 3],
    'four parts' => [3, 4],
    'two other parts' => [4, 2],
    'five parts' => [5, 5],
    'every line a part of its own' => [6, 64],
])->with([
    'by the plan\'s table' => [true],
    'by each part making its file whole' => [false],
]);

it('makes the file whole to number its mutants, and says so, where the plan tabled its code as it was before', function (): void {
    $root = Scratch::directory();
    $file = repeatedFile($root);
    $making = repeatedMaking();
    repeatedTable($root, $file);
    repeatedResults($root);
    Scratch::write($root, 'src/Packs.php', str_replace('return [', "return [\n            2,", repeatedSource()));
    $whole = repeatedIds($root, $making($file, []), placed: false);
    $made = SideBySideMutants::made([$file], static fn(): array => [27, 28], $making, cut: true)->of($file, []);
    $placed = repeatedIds($root, $made, placed: true);

    expect($placed)->not->toBe([])
        ->and(array_map(static fn(MutantId $id): string => $id->value(), array_values($placed)))
        ->toBe(array_map(static fn(string $spot): string => $whole[$spot]->value(), array_keys($placed)))
        ->and(repeatedUnplaced($root))->toBe([strval($file->getRealPath())])
        ->and(array_map(
            static fn(Warning $warning): string => $warning->text(),
            [...UnplacedFiles::warned(repeatedResultsOf($root), repeatedProject($root))],
        ))->toBe([
            'Made 1 file whole to number the mutants of some lines, src/Packs.php first: the plan\'s table did not match (ADR-0004).',
        ])
        ->and(repeatedUnplaced($root))->toBe([]);
});

it('reads no table another install of Pest made', function (): void {
    $root = Scratch::directory();
    $file = repeatedFile($root);
    repeatedTable($root, $file);
    $table = sprintf('%s/.mutation-gate/%s', $root, ChangeTable::FILE);
    Scratch::write($root, sprintf('.mutation-gate/%s', ChangeTable::FILE), str_replace('"madeBy":"', '"madeBy":"another ', (string) file_get_contents($table)));
    repeatedResults($root);
    $made = SideBySideMutants::made([$file], static fn(): array => [8], repeatedMaking(), cut: true)->of($file, []);

    expect($made)->not->toBe([])
        ->and(ChangeTable::told())->toEqual(ChangeTable::none())
        ->and(repeatedUnplaced($root))->toBe([strval($file->getRealPath())]);
});

it('tables where every mutant of a file starts, as a run that makes the whole file makes them', function (): void {
    $root = Scratch::directory();
    $file = repeatedFile($root);
    repeatedTable($root, $file);
    $starts = array_map(static fn(Mutation $mutant): int => $mutant->startLine, repeatedMaking()($file, []));
    $sites = ChangeTable::in(sprintf('%s/.mutation-gate/%s', $root, ChangeTable::FILE))
        ->sitesOf(repeatedProject($root), Paths::of(Path::of('src/Packs.php'), Path::of('src/Absent.php')));
    $counted = [];

    foreach ($sites->linesOf(Path::of('src/Packs.php')) as $line) {
        $counted[$line->number()] = $sites->countAt(Path::of('src/Packs.php'), $line);
    }

    expect($counted)->toBe(array_count_values($starts))
        ->and($sites->count())->toBe(count($starts))
        ->and($sites->has(Path::of('src/Absent.php')))->toBeFalse();
});

it('numbers the second of two lines alike as the second where a part makes it alone, as the gate\'s own run left it out', function (): void {
    $root = Scratch::directory();
    $file = repeatedFile($root);
    $making = repeatedMaking();
    $whole = repeatedIds($root, $making($file, []), placed: false);
    $made = SideBySideMutants::made([$file], static fn(): array => [20], $making, cut: true)->of($file, []);
    $unplaced = repeatedIds($root, $made, placed: false);
    $placed = repeatedIds($root, $made, placed: true);
    $spots = array_keys($placed);

    expect($placed)->not->toBe([])
        ->and(array_map(static fn(string $spot): string => $whole[$spot]->value(), $spots))
        ->toBe(array_map(static fn(MutantId $id): string => $id->value(), array_values($placed)))
        ->and(array_map(static fn(MutantId $id): string => $id->value(), array_values($unplaced)))
        ->not->toBe(array_map(static fn(MutantId $id): string => $id->value(), array_values($placed)));
});

it('numbers every mutant as before where the run makes the whole file', function (): void {
    $root = Scratch::directory();
    $file = repeatedFile($root);
    $making = repeatedMaking();
    $made = SideBySideMutants::made([$file], static fn(): array => [], $making, cut: true)->of($file, []);

    expect(repeatedIds($root, $made, placed: true))->toEqual(repeatedIds($root, $made, placed: false));
});

/**
 * A part's mutants as the verdict reads them, each by its gate id.
 *
 * @param array<int, Mutation>    $made
 * @param array<string, MutantId> $ids
 */
function repeatedMutants(array $made, array $ids): Mutants
{
    return Mutants::of(...array_map(static fn(Mutation $mutant): Mutant => Mutant::of(
        $ids[sprintf('%s:%d:%d', $mutant->id, $mutant->startLine, $mutant->endLine)],
        $mutant->id,
        Location::of(Path::of('src/Packs.php'), Line::of($mutant->startLine), Line::of($mutant->endLine)),
        GateMutation::of($mutant->mutator, MutatorFamily::Arithmetic, $mutant->diff),
        MutantStatus::Survived,
        Unmeasured::duration(),
    ), array_values($made)));
}

/**
 * The lines a part made, one span each, of a file of this many lines.
 *
 * @param list<int> $lines
 */
function repeatedMade(int $last, array $lines): MadeLines
{
    return MadeLines::whole()->with(MadeFile::of(
        Path::of('src/Packs.php'),
        Line::of($last),
        ...array_map(static fn(int $line): LineSpan => LineSpan::of(Line::of($line), Line::of($line)), $lines),
    ));
}
