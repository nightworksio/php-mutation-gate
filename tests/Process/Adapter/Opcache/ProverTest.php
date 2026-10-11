<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Compiler;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Adapter\Opcache\Uncompiled;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Tests\Support\ProverPrograms;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Opcache/Compiler.php',
    'holds:src/Adapter/Opcache/Prover.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The pair of ProverPrograms::PROVED and it with one change, under a key.
 *
 * @return array{string, Contents, Contents}
 */
function provedPair(string $key, string $from, string $to): array
{
    return [$key, Contents::of(ProverPrograms::PROVED), Contents::of(str_replace($from, $to, ProverPrograms::PROVED))];
}

it('proves a mutant that compiles to its original and declares what it declares, and no other', function (): void {
    $prover = Prover::of(PHP_BINARY, Root::of(Scratch::directory())->at(Path::of('equivalence')), ProcessCount::of(2), disabled: false);

    expect($prover->proven([
        provedPair('same', '$a * 2', '$a + $a'),
        provedPair('opcodes', '$a * 2', '$a * 3'),
        provedPair('declaration', 'MOST = 10', 'MOST = 11'),
        provedPair('unparsed', 'return', 'return return'),
        provedPair('again', '$a * 2', '2 * $a'),
        ['123456789012', Contents::of(ProverPrograms::PROVED), Contents::of(ProverPrograms::PROVED)],
    ]))->toBe(['same', 'again', '123456789012']);
})->group(...$holds);

it('says opcache dumped nothing, and proves none, where it gives no opcodes', function (): void {
    $compiler = new Compiler(PHP_BINARY, Scratch::directory(), 30.0, 1, ['opcache.opt_debug_level=0']);

    expect(new Prover($compiler)->proven([provedPair('same', '$a * 2', '$a + $a')]))->toBe(Uncompiled::NoOpcache);
})->group(...$holds);

/** A program that reads the PHP it runs on in ways opcache decides as it compiles, beside a function that does not. */
const DECIDED = <<<'PHP_WRAP'
<?php

function future(): int
{
    if (PHP_VERSION_ID >= 990000) {
        return 1;
    }

    return 2;
}

function chained(int $n): int
{
    if ($n > 0) {
        return 1;
    } elseif (\Extension_Loaded('Core')) {
        return 2;
    } else {
        return 3;
    }
}

function named(): int
{
    return defined('PHP_EOL') ? 1 : 2;
}

function counted(): int
{
    $exists = \function_exists('strlen');

    return $exists ? 1 : 2;
}

function wide(): int
{
    return PHP_INT_SIZE === 8 ? 1 : 2;
}

function doubled(int $a): int
{
    return $a * 2;
}
PHP_WRAP;

it('proves no mutant that changes what opcache decides by the PHP it compiles on, and still proves one beside it', function (): void {
    $prover = Prover::of(PHP_BINARY, Root::of(Scratch::directory())->at(Path::of('equivalence')), ProcessCount::of(1), disabled: false);
    $pair = static fn(string $key, string $from, string $to): array => [$key, Contents::of(DECIDED), Contents::of(str_replace($from, $to, DECIDED))];
    $pairs = [
        $pair('a dead branch', "return 1;\n    }\n\n    return 2;", "return 5;\n    }\n\n    return 2;"),
        $pair('its condition', 'PHP_VERSION_ID >= 990000', 'PHP_VERSION_ID > 990000'),
        $pair('a branch an elseif decides', 'return 3;', 'return 5;'),
        $pair('a ternary', "defined('PHP_EOL') ? 1 : 2", "defined('PHP_EOL') ? 1 : 9"),
        $pair('a constant', 'PHP_INT_SIZE === 8 ? 1 : 2', 'PHP_INT_SIZE === 8 ? 1 : 4'),
        $pair('through a variable', '$exists ? 1 : 2', '$exists ? 1 : 6'),
        $pair('elsewhere', '$a * 2', '$a + $a'),
    ];

    expect(array_filter($pairs, static fn(array $pair): bool => $pair[1] === $pair[2]))->toBe([])
        ->and($prover->proven($pairs))->toBe(['elsewhere']);
})->group(...$holds);

it('proves no mutant of a function the tests\' PHP disables, which opcache would otherwise answer for it', function (): void {
    $prover = static fn(string|false $disabled): Prover => Prover::of(
        PHP_BINARY,
        Root::of(Scratch::directory())->at(Path::of('equivalence')),
        ProcessCount::of(1),
        $disabled,
    );
    $pair = [
        'counted',
        Contents::of("<?php\n\nfunction three(): int\n{\n    return strlen('abc');\n}\n"),
        Contents::of("<?php\n\nfunction three(): int\n{\n    return 3;\n}\n"),
    ];

    expect($prover(disabled: false)->proven([$pair]))->toBe(['counted'])
        ->and($prover('')->proven([$pair]))->toBe(['counted'])
        ->and($prover('strlen')->proven([$pair]))->toBe([])
        ->and($prover('exec,strlen')->proven([$pair]))->toBe([]);
})->group(...$holds);
