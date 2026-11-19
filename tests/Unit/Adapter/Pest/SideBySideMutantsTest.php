<?php

declare(strict_types=1);

use Fidry\CpuCoreCounter\CpuCoreCounter;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Adapter\Pest\SideBySideMutants;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutation;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});

/**
 * Three files of a project, as Pest finds them.
 *
 * @return list<SplFileInfo>
 */
function madeFiles(): array
{
    $root = Scratch::directory();

    return array_map(static function (string $name) use ($root): SplFileInfo {
        Scratch::write($root, sprintf('src/%s.php', $name), sprintf("<?php\n\nreturn '%s';\n", str_repeat($name, 40)));

        return new SplFileInfo(sprintf('%s/src/%s.php', $root, $name), 'src', sprintf('src/%s.php', $name));
    }, ['Money', 'Tax', 'Rate']);
}

/**
 * What a run makes of each file: two mutants on a line that tells how many lines it mutates, each file made noted by
 * the process that made it.
 *
 * @param  ArrayObject<int, int>                                 $makers the id of the process that made each file
 * @return Closure(SplFileInfo, list<int>): array<int, Mutation>
 */
function countedMaking(ArrayObject $makers): Closure
{
    return static function (SplFileInfo $file, array $lines) use ($makers): array {
        $makers->append(posix_getpid());
        $line = 3 + count($lines);
        $path = strval($file->getRealPath());

        return [
            Mutations::mutation($path, sprintf('%s-1', basename($path)), $line, sprintf('%s.one', $path)),
            Mutations::mutation($path, sprintf('%s-2', basename($path)), $line + 1, sprintf('%s.two', $path)),
        ];
    };
}

/**
 * Each mutant's fields, as the run takes them.
 *
 * @param  array<int, Mutation> $mutants
 * @return list<list<int|string>>
 */
function madeFields(array $mutants): array
{
    return array_values(array_map(static fn(Mutation $mutant): array => [
        strval($mutant->file->getRealPath()),
        $mutant->id,
        $mutant->mutator,
        $mutant->startLine,
        $mutant->endLine,
        $mutant->diff,
        $mutant->modifiedSourcePath,
    ], $mutants));
}

it('makes every file\'s mutants in children side by side where the gate asks, and hands each file\'s as made there', function (): void {
    putenv(sprintf('%s=1', GateVariable::MakingSideBySide->value));
    $files = madeFiles();
    $makers = new ArrayObject();
    $made = SideBySideMutants::made($files, static fn(): array => [7], countedMaking($makers));
    $expected = countedMaking(new ArrayObject());

    expect(array_map(static fn(SplFileInfo $file): array => madeFields($made->of($file, [7])), $files))
        ->toBe(array_map(static fn(SplFileInfo $file): array => madeFields($expected($file, [7])), $files))
        ->and($makers->getArrayCopy())->toBe([]);
})->skip(fn(): bool => ! function_exists('pcntl_fork'), 'The process cannot fork.');

it('hands on a mutant whose diff or copy holds bytes that are not UTF-8 as the child made it', function (): void {
    putenv(sprintf('%s=1', GateVariable::MakingSideBySide->value));
    $files = madeFiles();
    $made = SideBySideMutants::made(
        $files,
        static fn(): array => [],
        static fn(SplFileInfo $file): array => [new Mutation($file, 'latin', 'Mutator', 3, 4, "- '\xE9t\xE9'", "/copies/\xE9")],
    );
    $mutant = $made->of($files[0], [])[0];

    expect([$mutant->diff, $mutant->modifiedSourcePath])->toBe(["- '\xE9t\xE9'", "/copies/\xE9"]);
})->skip(fn(): bool => ! function_exists('pcntl_fork'), 'The process cannot fork.');

it('makes each file\'s mutants of the lines the run asked for as it is taken where the gate does not ask, and none of a file it asked for no line of', function (): void {
    $files = madeFiles();
    $makers = new ArrayObject();
    $made = SideBySideMutants::made(
        $files,
        static fn(SplFileInfo $file): array|false => $file === $files[0] ? false : [5, 6],
        countedMaking($makers),
    );
    $unasked = $makers->getArrayCopy();

    expect($unasked)->toBe([])
        ->and($made->of($files[0], []))->toBe([])
        ->and($makers->getArrayCopy())->toBe([])
        ->and(array_map(static fn(Mutation $mutant): int => $mutant->startLine, $made->of($files[1], [])))->toBe([5, 6])
        ->and($makers->getArrayCopy())->toBe([posix_getpid()]);
});

it('makes here the mutants of the files a child failed to make', function (): void {
    putenv(sprintf('%s=1', GateVariable::MakingSideBySide->value));
    $files = madeFiles();
    $parent = posix_getpid();
    $makers = new ArrayObject();
    $counted = countedMaking($makers);
    $made = SideBySideMutants::made(
        $files,
        static fn(): array => [],
        static fn(SplFileInfo $file, array $lines): array => posix_getpid() === $parent
            ? $counted($file, $lines)
            : throw NotInShape::missing('the child'),
    );
    $taken = madeFields($made->of($files[1], []));

    expect($taken)->toBe(madeFields(countedMaking(new ArrayObject())($files[1], [])))
        ->and($makers->getArrayCopy())->toBe([$parent]);
})->skip(fn(): bool => ! function_exists('pcntl_fork'), 'The process cannot fork.');

it('makes here the mutants of the files whose child left nothing whole, though a file it cannot read lies where that child would have left its own', function (): void {
    putenv(sprintf('%s=1', GateVariable::MakingSideBySide->value));
    $files = madeFiles();
    $parent = posix_getpid();
    $stale = array_map(
        static fn(int $share): string => sprintf('%s/mutation-gate-made-%d-%d.json', sys_get_temp_dir(), $parent, $share),
        array_keys($files),
    );
    array_map(static fn(string $left): int|false => file_put_contents($left, '{"made": "unreadable"}'), $stale);
    $makers = new ArrayObject();
    $counted = countedMaking($makers);
    $made = SideBySideMutants::made(
        $files,
        static fn(): array => [],
        static fn(SplFileInfo $file, array $lines): array => posix_getpid() === $parent
            ? $counted($file, $lines)
            : throw NotInShape::missing('the child'),
    );
    $taken = array_map(static fn(SplFileInfo $file): array => madeFields($made->of($file, [])), $files);
    $left = array_values(array_filter($stale, is_file(...)));
    array_map(unlink(...), $left);

    expect($taken)->toBe(array_map(static fn(SplFileInfo $file): array => madeFields(countedMaking(new ArrayObject())($file, [])), $files))
        ->and($makers->getArrayCopy())->toBe([$parent, $parent, $parent])
        ->and($left)->toBe([]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || new CpuCoreCounter()->getCountWithFallback(1) < 3, 'The process cannot fork, or has fewer cores than files.');

it('hands on the schemata a child wrote, so the run tells each mutant its schema, though its id is all digits', function (): void {
    putenv(sprintf('%s=1', GateVariable::MakingSideBySide->value));
    $files = madeFiles();
    $code = "<?php\n\nreturn 1 + 2;\n";
    $makers = new ArrayObject();
    $made = SideBySideMutants::made(
        $files,
        static fn(): array => [],
        static function (SplFileInfo $file) use ($code, $makers): array {
            $makers->append(posix_getpid());
            $path = strval($file->getRealPath());
            $mutant = Mutations::mutation($path, sprintf('%d', strlen($path)), 3, sprintf('%s/copies/one', dirname($path)));
            $plus = new NodeFinder()->findFirstInstanceOf(new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [], Plus::class);
            $plus instanceof Plus ? Schemata::sited($mutant, $plus, new Minus($plus->left, $plus->right)) : null;
            Schemata::written($path, $code, sprintf('%s/copies', dirname($path)));

            return [$mutant];
        },
    );
    $told = Schemata::variablesOf($made->of($files[2], [])[0]);

    expect($makers->getArrayCopy())->toBe([])
        ->and(array_keys($told))->toBe([GateVariable::Schema->value, GateVariable::SchemaOn->value])
        ->and($told[GateVariable::SchemaOn->value])->toBe('1')
        ->and(file_get_contents($told[GateVariable::Schema->value]))->toContain('? (1 - 2) : (1 + 2)');
})->skip(fn(): bool => ! function_exists('pcntl_fork'), 'The process cannot fork.');
