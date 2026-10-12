<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Compiler;
use NightWorksIO\MutationGate\Adapter\Opcache\Opcodes;
use NightWorksIO\MutationGate\Adapter\Opcache\Uncompiled;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Tests\Support\CompilerChildren;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Opcache/Compiler.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

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
        'original' => Contents::of(CompilerChildren::PROGRAM),
        'swapped' => Contents::of(str_replace('2 * 3', '3 * 2', CompilerChildren::PROGRAM)),
        'changed' => Contents::of(str_replace('2 * 3', '2 * 4', CompilerChildren::PROGRAM)),
    ], $parallel);

    expect(array_keys($compiled))->toBe(['original', 'swapped', 'changed'])
        ->and(compiledAs($compiled, 'original', 'swapped'))->toBeTrue()
        ->and(compiledAs($compiled, 'original', 'changed'))->toBeFalse()
        ->and($compiled['original'] instanceof Opcodes ? $compiled['original']->text() : '')->toContain('RETURN int(6)');
})->with([1, 2, 3])->group(...$holds);

it('says a program that does not compile failed, and compiles every other one of its child alone', function (): void {
    $compiled = compiledPrograms([
        'parse error' => Contents::of('<?php function ('),
        'compile error' => Contents::of("<?php\nclass Twice { function f() {} function f() {} }\n"),
        'original' => Contents::of(CompilerChildren::PROGRAM),
        'again' => Contents::of(CompilerChildren::PROGRAM),
    ], 1);

    expect($compiled['parse error'])->toBe(Uncompiled::Failed)
        ->and($compiled['compile error'])->toBe(Uncompiled::Failed)
        ->and(compiledAs($compiled, 'original', 'again'))->toBeTrue();
})->group(...$holds);

it('compiles a program that warns as it compiles, with no warning in its answer or its opcodes', function (): void {
    $warning = "<?php\nfunction late(\$a = 1, \$b) { return \$a + \$b; }\n";
    $compiled = compiledPrograms(['warns' => Contents::of($warning), 'again' => Contents::of($warning)], 1);

    expect(compiledAs($compiled, 'warns', 'again'))->toBeTrue()
        ->and($compiled['warns'] instanceof Opcodes ? $compiled['warns']->text() : '')->not->toContain('Deprecated');
})->group(...$holds);

it('compiles at most a hundred programs in one child', function (int $programs, int $children): void {
    $log = sprintf('%s/children', Scratch::directory());
    $child = sprintf('%s/counting-php', Scratch::directory());
    file_put_contents($child, sprintf("#!/bin/sh\necho started >> %s\nexec %s \"$@\"\n", $log, PHP_BINARY));
    chmod($child, 0o755);
    $listed = [];

    foreach (range(1, $programs) as $at) {
        $listed[sprintf('p%d', $at)] = Contents::of(sprintf("<?php\nfunction p%d(): int { return %d; }\n", $at, $at));
    }

    $compiled = new Compiler($child, sprintf('%s/equivalence', Scratch::directory()), 30.0, 1)->compiled($listed);

    expect(array_filter($compiled, static fn(Opcodes|Uncompiled $one): bool => ! $one instanceof Opcodes))->toBe([])
        ->and(substr_count((string) file_get_contents($log), 'started'))->toBe($children);
})->with(['a hundred, in one child' => [100, 1], 'one more, in two' => [101, 2]])->group(...$holds);

it('says opcache dumped nothing where it gives no opcodes, or cannot compile at all', function (string $setting): void {
    expect(compiledPrograms(['one' => Contents::of(CompilerChildren::PROGRAM)], besides: [$setting]))
        ->toBe(['one' => Uncompiled::NoOpcache]);
})->with(['opcache.opt_debug_level=0', 'disable_functions=opcache_compile_file'])->group(...$holds);

it('reads no php.ini, so no setting of the project\'s reaches the child', function (): void {
    $ini = sprintf('%s/php.ini', Scratch::directory());
    file_put_contents($ini, "disable_functions=opcache_compile_file\n");
    $_ENV['PHPRC'] = $ini;

    try {
        $compiled = compiledPrograms(['one' => Contents::of(CompilerChildren::PROGRAM)]);
    } finally {
        unset($_ENV['PHPRC']);
    }

    expect($compiled['one'])->toBeInstanceOf(Opcodes::class);
})->group(...$holds);

it('reads each program of a child that ends well and dumps one section for each', function (): void {
    $dump = CompilerChildren::dump("\n\$_main:\n0000 RETURN int(1)\n", "\n\$_main:\n0000 RETURN int(2)\n");
    $compiled = new Compiler(CompilerChildren::child($dump, 0), sprintf('%s/equivalence', Scratch::directory()), 30.0, 1)
        ->compiled(['one' => Contents::of(CompilerChildren::PROGRAM), 'two' => Contents::of(CompilerChildren::PROGRAM)]);

    expect(array_map(static fn(Opcodes|Uncompiled $one): string => $one instanceof Opcodes ? $one->text() : $one->name, $compiled))
        ->toBe(['one' => "\n\$_main:\n0000 RETURN int(1)\n", 'two' => "\n\$_main:\n0000 RETURN int(2)\n"]);
})->group(...$holds);
