<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Compiler;
use NightWorksIO\MutationGate\Adapter\Opcache\Uncompiled;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Tests\Support\CompilerChildren;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** How long the sleeping child runs: far past any time a test gives it. */
const SLEEPING_CHILD_SECONDS = 30;

it('takes away every program it wrote once they are compiled', function (): void {
    $directory = sprintf('%s/equivalence', Scratch::directory());
    new Compiler(PHP_BINARY, $directory, 30.0, 1)->compiled(['one' => Contents::of(CompilerChildren::PROGRAM)]);

    expect(glob(sprintf('%s/*', $directory)))->toBe([]);
});

it('says each program failed where its child cannot start or is given no time', function (string $binary, float $seconds): void {
    $compiler = new Compiler($binary, sprintf('%s/equivalence', Scratch::directory()), $seconds, 1);

    expect($compiler->compiled(['one' => Contents::of(CompilerChildren::PROGRAM), 'two' => Contents::of(CompilerChildren::PROGRAM)]))
        ->toBe(['one' => Uncompiled::Failed, 'two' => Uncompiled::Failed]);
})->with([
    'no PHP there' => [fn(): string => sprintf('%s/no-such-php', __DIR__), 30.0],
    'no time at all' => [PHP_BINARY, -1.0],
]);

it('stops a child that runs past its time, and says each of its programs failed', function (): void {
    $compiler = new Compiler(sleepingChild(), sprintf('%s/equivalence', Scratch::directory()), 0.2, 1);
    $started = hrtime(as_number: true);

    expect($compiler->compiled(['one' => Contents::of(CompilerChildren::PROGRAM), 'two' => Contents::of(CompilerChildren::PROGRAM)]))
        ->toBe(['one' => Uncompiled::Failed, 'two' => Uncompiled::Failed])
        ->and((hrtime(as_number: true) - $started) / 1e9)->toBeLessThan(SLEEPING_CHILD_SECONDS / 2);
});

/** A child that is still running long after any time a test gives it. */
function sleepingChild(): string
{
    $child = sprintf('%s/sleeping-php', Scratch::directory());
    file_put_contents($child, sprintf("#!%s\n<?php\nsleep(%d);\n", PHP_BINARY, SLEEPING_CHILD_SECONDS));
    chmod($child, 0o755);

    return $child;
}

it('says each program failed where its child\'s dump does not read back whole, rather than compare what it holds', function (
    string $dump,
    int $exit,
): void {
    $compiler = new Compiler(CompilerChildren::child($dump, $exit), sprintf('%s/equivalence', Scratch::directory()), 30.0, 1);

    expect($compiler->compiled(['one' => Contents::of(CompilerChildren::PROGRAM), 'two' => Contents::of(CompilerChildren::PROGRAM)]))
        ->toBe(['one' => Uncompiled::Failed, 'two' => Uncompiled::Failed]);
})->with([
    'a child that exits with an error' => [fn(): string => CompilerChildren::dump("\n\$_main:\n0000 RETURN int(1)\n", "\n\$_main:\n0000 RETURN int(1)\n"), 1],
    'more sections than programs' => [
        fn(): string => CompilerChildren::dump("\n\$_main:\n0000 RETURN int(1)\n", "\n\$_main:\n0000 RETURN int(1)\n", "\n\$_main:\n0000 RETURN int(2)\n"),
        0,
    ],
    'fewer sections than programs' => [fn(): string => CompilerChildren::dump("\n\$_main:\n0000 RETURN int(1)\n"), 0],
    'sections that are not dumps' => [fn(): string => CompilerChildren::dump("Opcache cannot allocate shared memory\n", "Opcache cannot allocate shared memory\n"), 0],
]);
