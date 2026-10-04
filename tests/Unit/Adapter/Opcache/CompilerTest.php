<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Compiler;
use NightWorksIO\MutationGate\Adapter\Opcache\Opcodes;
use NightWorksIO\MutationGate\Adapter\Opcache\Uncompiled;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

const COMPILED_PROGRAM = "<?php\nfunction six(): int { return 2 * 3; }\n";

/**
 * @param  array<string, Contents>           $programs
 * @param  list<string>                      $besides
 * @return array<string, Opcodes|Uncompiled>
 */
function compiledPrograms(array $programs, int $parallel = 2, array $besides = []): array
{
    return new Compiler(PHP_BINARY, sprintf('%s/equivalence', Scratch::directory()), 30.0, max(1, $parallel), $besides)
        ->compiled($programs);
}

/** @param array<string, Opcodes|Uncompiled> $compiled */
function compiledAs(array $compiled, string $one, string $other): bool
{
    return $compiled[$one] instanceof Opcodes && $compiled[$other] instanceof Opcodes && $compiled[$one]->same($compiled[$other]);
}

it('compiles each program to its optimized opcodes, the same for a program the optimizer makes the same', function (int $parallel): void {
    $compiled = compiledPrograms([
        'original' => Contents::of(COMPILED_PROGRAM),
        'swapped' => Contents::of(str_replace('2 * 3', '3 * 2', COMPILED_PROGRAM)),
        'changed' => Contents::of(str_replace('2 * 3', '2 * 4', COMPILED_PROGRAM)),
    ], $parallel);

    expect(array_keys($compiled))->toBe(['original', 'swapped', 'changed'])
        ->and(compiledAs($compiled, 'original', 'swapped'))->toBeTrue()
        ->and(compiledAs($compiled, 'original', 'changed'))->toBeFalse()
        ->and($compiled['original'] instanceof Opcodes ? $compiled['original']->text() : '')->toContain('RETURN int(6)');
})->with([1, 2, 3]);

it('says a program that does not compile failed, and compiles every other one of its child alone', function (): void {
    $compiled = compiledPrograms([
        'parse error' => Contents::of('<?php function ('),
        'compile error' => Contents::of("<?php\nclass Twice { function f() {} function f() {} }\n"),
        'original' => Contents::of(COMPILED_PROGRAM),
        'again' => Contents::of(COMPILED_PROGRAM),
    ], 1);

    expect($compiled['parse error'])->toBe(Uncompiled::Failed)
        ->and($compiled['compile error'])->toBe(Uncompiled::Failed)
        ->and(compiledAs($compiled, 'original', 'again'))->toBeTrue();
});

it('takes away every program it wrote once they are compiled', function (): void {
    $directory = sprintf('%s/equivalence', Scratch::directory());
    new Compiler(PHP_BINARY, $directory, 30.0, 1)->compiled(['one' => Contents::of(COMPILED_PROGRAM)]);

    expect(glob(sprintf('%s/*', $directory)))->toBe([]);
});

it('says opcache dumped nothing where it gives no opcodes, or cannot compile at all', function (string $setting): void {
    expect(compiledPrograms(['one' => Contents::of(COMPILED_PROGRAM)], besides: [$setting]))
        ->toBe(['one' => Uncompiled::NoOpcache]);
})->with(['opcache.opt_debug_level=0', 'disable_functions=opcache_compile_file']);

it('reads no php.ini, so no setting of the project\'s reaches the child', function (): void {
    $ini = sprintf('%s/php.ini', Scratch::directory());
    file_put_contents($ini, "disable_functions=opcache_compile_file\n");
    $_ENV['PHPRC'] = $ini;

    try {
        $compiled = compiledPrograms(['one' => Contents::of(COMPILED_PROGRAM)]);
    } finally {
        unset($_ENV['PHPRC']);
    }

    expect($compiled['one'])->toBeInstanceOf(Opcodes::class);
});

it('says each program failed where its child cannot start, is given no time or runs out of it', function (string $binary, float $seconds): void {
    $compiler = new Compiler($binary, sprintf('%s/equivalence', Scratch::directory()), $seconds, 1);

    expect($compiler->compiled(['one' => Contents::of(COMPILED_PROGRAM), 'two' => Contents::of(COMPILED_PROGRAM)]))
        ->toBe(['one' => Uncompiled::Failed, 'two' => Uncompiled::Failed]);
})->with([
    'no PHP there' => [sprintf('%s/no-such-php', __DIR__), 30.0],
    'no time at all' => [PHP_BINARY, -1.0],
    'no time to compile' => [PHP_BINARY, 0.001],
]);

/** What a child writes to the dump's stream: each of these sections after the marker. */
function dumpSections(string ...$sections): string
{
    $written = '';

    foreach ($sections as $section) {
        $written .= sprintf("\x1Emutation-gate\x1E%s", $section);
    }

    return $written;
}

/** A child that compiles nothing, but answers both of two programs compiled, writes this dump, and exits so. */
function fakeChild(string $dump, int $exit): string
{
    $child = sprintf('%s/fake-php', Scratch::directory());
    file_put_contents($child, sprintf(
        "#!%s\n<?php\nfwrite(STDERR, %s);\necho '11';\nexit(%d);\n",
        PHP_BINARY,
        var_export($dump, return: true),
        $exit,
    ));
    chmod($child, 0o755);

    return $child;
}

it('says each program failed where its child\'s dump does not read back whole, rather than compare what it holds', function (
    string $dump,
    int $exit,
): void {
    $compiler = new Compiler(fakeChild($dump, $exit), sprintf('%s/equivalence', Scratch::directory()), 30.0, 1);

    expect($compiler->compiled(['one' => Contents::of(COMPILED_PROGRAM), 'two' => Contents::of(COMPILED_PROGRAM)]))
        ->toBe(['one' => Uncompiled::Failed, 'two' => Uncompiled::Failed]);
})->with([
    'a child that exits with an error' => [dumpSections("\n\$_main:\n0000 RETURN int(1)\n", "\n\$_main:\n0000 RETURN int(1)\n"), 1],
    'more sections than programs' => [
        dumpSections("\n\$_main:\n0000 RETURN int(1)\n", "\n\$_main:\n0000 RETURN int(1)\n", "\n\$_main:\n0000 RETURN int(2)\n"),
        0,
    ],
    'fewer sections than programs' => [dumpSections("\n\$_main:\n0000 RETURN int(1)\n"), 0],
    'sections that are not dumps' => [dumpSections("Opcache cannot allocate shared memory\n", "Opcache cannot allocate shared memory\n"), 0],
]);

it('reads each program of a child that ends well and dumps one section for each', function (): void {
    $dump = dumpSections("\n\$_main:\n0000 RETURN int(1)\n", "\n\$_main:\n0000 RETURN int(2)\n");
    $compiled = new Compiler(fakeChild($dump, 0), sprintf('%s/equivalence', Scratch::directory()), 30.0, 1)
        ->compiled(['one' => Contents::of(COMPILED_PROGRAM), 'two' => Contents::of(COMPILED_PROGRAM)]);

    expect(array_map(static fn(Opcodes|Uncompiled $one): string => $one instanceof Opcodes ? $one->text() : $one->name, $compiled))
        ->toBe(['one' => "\n\$_main:\n0000 RETURN int(1)\n", 'two' => "\n\$_main:\n0000 RETURN int(2)\n"]);
});
