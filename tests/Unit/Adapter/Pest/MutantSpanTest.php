<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\MutantSpan;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
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
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Cache\NullStore;
use Pest\Mutate\Mutation;
use Pest\Mutate\Mutators\Sets\DefaultSet;
use Pest\Mutate\Support\MutationGenerator;
use Pest\Support\Container;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
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
 * A file whose mutants span several lines: parameters with defaults of a
 * constructor and a method whose bodies run on, a closure's, a call and an
 * array that span lines.
 */
function spannedSource(): string
{
    return <<<'PHP'
        <?php

        final class Stops
        {
            public function __construct(
                private int $target = 600,
                private bool $alone = false,
            ) {
            }

            public function after(int $seconds = 35, bool $strict = true): int
            {
                $total = $seconds + $this->target;

                return $strict ? max(
                    $total,
                    1,
                ) : $total - 1;
            }

            public function each(): array
            {
                $add = static fn(int $one, int $more = 2): int => $one + $more;

                return [
                    $add(1),
                    $this->alone ? 1 : 0,
                ];
            }
        }

        PHP;
}

/** The file, written under a project's root, as Pest finds it. */
function spannedFile(string $root): SplFileInfo
{
    Scratch::write($root, 'src/Stops.php', spannedSource());

    return new SplFileInfo(sprintf('%s/src/Stops.php', $root), 'src', 'src/Stops.php');
}

/**
 * The mutants Pest makes of the file's lines, every line where none are named.
 *
 * @param  list<int>      $lines
 * @return list<Mutation>
 */
function spannedMade(SplFileInfo $file, array $lines): array
{
    return array_values(new MutationGenerator()->generate($file, DefaultSet::mutators(), $lines));
}

/**
 * The ids of these mutants whose recorded lines touch one of these, in byte order.
 *
 * @param  list<Mutation> $mutants
 * @param  list<int>      $lines
 * @return list<string>
 */
function spannedTouching(array $mutants, array $lines): array
{
    $ids = [];

    foreach ($mutants as $mutant) {
        $touches = array_intersect(range($mutant->startLine, $mutant->endLine), $lines) !== [];
        $ids = $touches ? [...$ids, $mutant->id] : $ids;
    }

    sort($ids);

    return $ids;
}

/**
 * A part's mutants as the verdict reads them, each by Pest's own id, and the lines the part made.
 *
 * @param  list<Mutation>                 $made
 * @param  list<int>                      $lines
 * @return array{Mutants, MadeLines}
 */
function spannedPart(array $made, array $lines, int $last): array
{
    $mutants = Mutants::of(...array_map(static fn(Mutation $mutant): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Stops.php'), sprintf('%s %s', $mutant->mutator, $mutant->id), '', 0),
        $mutant->id,
        Location::of(Path::of('src/Stops.php'), Line::of($mutant->startLine), Line::of($mutant->endLine)),
        GateMutation::of($mutant->mutator, MutatorFamily::Arithmetic, $mutant->diff),
        MutantStatus::Survived,
        Unmeasured::duration(),
    ), $made));

    return [$mutants, MadeLines::whole()->with(MadeFile::of(
        Path::of('src/Stops.php'),
        Line::of($last),
        ...array_map(static fn(int $line): LineSpan => LineSpan::of(Line::of($line), Line::of($line)), $lines),
    ))];
}

/**
 * The file's lines cut into parts: at every line in two, by a seed into
 * several, and each line a part of its own.
 *
 * @return iterable<string, list<list<int>>>
 */
function spannedCuts(int $count): iterable
{
    for ($at = 1; $at < $count; $at++) {
        yield sprintf('in two after line %d', $at) => [range(1, $at), range($at + 1, $count)];
    }

    foreach ([[1, 3], [2, 4], [3, 5], [4, 2]] as [$seed, $parts]) {
        mt_srand($seed);
        $split = array_fill(0, $parts, []);

        for ($line = 1; $line <= $count; $line++) {
            $split[mt_rand(0, $parts - 1)][] = $line;
        }

        yield sprintf('by seed %d into %d', $seed, $parts) => array_values(array_filter($split, static fn(array $part): bool => $part !== []));
    }

    yield 'each line a part of its own' => array_map(static fn(int $line): array => [$line], range(1, $count));
}

it('makes in each part exactly the mutants whose recorded lines touch a line it made, so the verdict judges every one, however the lines are cut', function (): void {
    $file = spannedFile(Scratch::directory());
    $whole = spannedMade($file, []);
    $count = substr_count(spannedSource(), "\n");
    $differing = [];
    $refused = [];

    foreach (spannedCuts($count) as $cut => $parts) {
        $merged = [];

        foreach ($parts as $lines) {
            $made = spannedMade($file, $lines);
            $ids = array_map(static fn(Mutation $mutant): string => $mutant->id, $made);
            sort($ids);
            $differing = $ids === spannedTouching($whole, $lines) ? $differing : [...$differing, $cut];
            $merged[] = spannedPart($made, $lines, $count);
        }

        $judged = MergedParts::made(Path::of('src/Stops.php'), $merged);
        $refused = $judged instanceof Mutants && count($judged) === count($whole) ? $refused : [...$refused, $cut];
    }

    expect(array_filter($whole, static fn(Mutation $mutant): bool => $mutant->endLine - $mutant->startLine > 1))->not->toBe([])
        ->and(array_unique($differing))->toBe([])
        ->and($refused)->toBe([]);
});

it('records a mutant of a method parameter as spanning the whole method, as before, and any other node as its own lines', function (): void {
    $file = spannedFile(Scratch::directory());
    $spans = [];

    foreach (spannedMade($file, []) as $mutant) {
        $spans[sprintf('%s@%d', $mutant->mutator, $mutant->startLine)] = $mutant->endLine;
    }

    expect($spans)->toMatchArray([
        'Pest\Mutate\Mutators\Logical\FalseToTrue@7' => 9,
        'Pest\Mutate\Mutators\Number\IncrementInteger@6' => 9,
        'Pest\Mutate\Mutators\Number\IncrementInteger@11' => 19,
        'Pest\Mutate\Mutators\Logical\TrueToFalse@11' => 19,
        'Pest\Mutate\Mutators\Number\IncrementInteger@23' => 23,
    ]);
});

it('takes a node of a method parameter as spanning to the method\'s last line, and touching a line where any it spans is one', function (): void {
    $statements = new ParserFactory()->createForHostVersion()->parse(spannedSource()) ?? [];
    new NodeTraverser(new ParentConnectingVisitor())->traverse($statements);
    $finder = new NodeFinder();
    $defaults = [];

    foreach ($finder->findInstanceOf($statements, Param::class) as $param) {
        $defaults = $param->default instanceof Expr ? [...$defaults, $param->default] : $defaults;
    }

    expect(array_map(static fn(Node $node): array => [$node->getStartLine(), MutantSpan::lastOf($node)], $defaults))
        ->toBe([[6, 9], [7, 9], [11, 19], [11, 19], [23, 23]])
        ->and(MutantSpan::touches($defaults[1], [9]))->toBeTrue()
        ->and(MutantSpan::touches($defaults[1], [6, 10]))->toBeFalse()
        ->and(MutantSpan::touches($defaults[4], [22, 24]))->toBeFalse()
        ->and(MutantSpan::touches($defaults[4], [23]))->toBeTrue();
});
