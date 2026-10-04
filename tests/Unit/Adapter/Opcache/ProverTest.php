<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Compiler;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Adapter\Opcache\Uncompiled;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

const PROVED = <<<'PHP'
    <?php

    final class Prices
    {
        public const MOST = 10;

        public function total(int $a): int
        {
            return $a * 2;
        }
    }
    PHP;

/**
 * The pair of PROVED and it with one change, under a key.
 *
 * @return array{string, Contents, Contents}
 */
function provedPair(string $key, string $from, string $to): array
{
    return [$key, Contents::of(PROVED), Contents::of(str_replace($from, $to, PROVED))];
}

it('proves a mutant that compiles to its original and declares what it declares, and no other', function (): void {
    $prover = Prover::of(PHP_BINARY, Root::of(Scratch::directory())->at(Path::of('equivalence')), Processes::of(2));

    expect($prover->proven([
        provedPair('same', '$a * 2', '$a + $a'),
        provedPair('opcodes', '$a * 2', '$a * 3'),
        provedPair('declaration', 'MOST = 10', 'MOST = 11'),
        provedPair('unparsed', 'return', 'return return'),
        provedPair('again', '$a * 2', '2 * $a'),
        ['123456789012', Contents::of(PROVED), Contents::of(PROVED)],
    ]))->toBe(['same', 'again', '123456789012']);
});

it('proves none where there is nothing to check', function (): void {
    expect(Prover::of(PHP_BINARY, Root::of(Scratch::directory())->at(Path::of('equivalence')), Processes::of(1))->proven([]))
        ->toBe([]);
});

it('says opcache dumped nothing, and proves none, where it gives no opcodes', function (): void {
    $compiler = new Compiler(PHP_BINARY, Scratch::directory(), 30.0, 1, ['opcache.opt_debug_level=0']);

    expect(new Prover($compiler)->proven([provedPair('same', '$a * 2', '$a + $a')]))->toBe(Uncompiled::NoOpcache);
});
