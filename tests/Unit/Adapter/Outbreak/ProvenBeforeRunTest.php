<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\PrintedDeclarations;
use NightWorksIO\MutationGate\Adapter\Outbreak\OpArrays;
use NightWorksIO\MutationGate\Adapter\Outbreak\ProvenBeforeRun;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\BeforeRunProofs;
use NightWorksIO\MutationGate\Core\Mutant\PrintsDeclarations;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('prints a program\'s declarations as its printer does, and none of a program that does not parse', function (): void {
    $proving = new ProvenBeforeRun(new OpArrays(PHP_BINARY, '/nowhere/outbreak.so', sys_get_temp_dir(), 1.0), new PrintedDeclarations());
    $program = Contents::of("<?php\nfinal class Money { public function add(int \$a): int { return \$a + 1; } }\n");

    expect($proving->declarations($program))->toBe(new PrintedDeclarations()->declarations($program))
        ->and($proving->declarations($program))->toBeString()
        ->and($proving->declarations(Contents::of('<?php final class {')))->toBeInstanceOf(NotGiven::class);
});

it('proves each mutant that compiled to its original\'s op arrays and declares what its original declares, and keeps the digest of each that compiled', function (): void {
    $alike = str_repeat('a', 64);
    $php = FakePhp::printing(sprintf("0.4.0\n%s\n%s\n%s\n%s\n%s\n-\n", $alike, str_repeat('b', 64), $alike, $alike, $alike));
    $printer = new class implements PrintsDeclarations {
        /** @var list<string> each program it printed */
        public private(set) array $printed = [];

        public function declarations(Contents $program): string|NotGiven
        {
            $this->printed[] = $program->text();

            return new PrintedDeclarations()->declarations($program);
        }
    };
    $original = "<?php\nfinal class Money { public function add(int \$a): int { return \$a + 1; } }\n";
    $mutant = static fn(string $from, string $to): string => str_replace($from, $to, $original);
    $proven = new ProvenBeforeRun(new OpArrays(sprintf('%s/php', $php), '/nowhere/outbreak.so', sprintf('%s/programs', $php), 5.0), $printer)
        ->proven([
            ['differs', Contents::of($original), Contents::of($mutant('+ 1', '- 1'))],
            ['first', Contents::of($original), Contents::of($mutant('$a + 1', '1 + $a'))],
            ['declares', Contents::of($original), Contents::of($mutant('int $a', 'int $b'))],
            ['second', Contents::of($original), Contents::of($mutant('+ 1', '+ 0 + 1'))],
            ['uncompiled', Contents::of($original), Contents::of($mutant('+ 1', '+'))],
        ]);

    expect(array_map(static fn(Reason $proof): string => $proof->text(), $proven->proofs))->toBe([
        'first' => sprintf('outbreak.so 0.4.0 compiled it to its original\'s op arrays before any test ran,%sand it declares what its original declares, so no test can fail on it (ADR-0130).', "\n"),
        'second' => sprintf('outbreak.so 0.4.0 compiled it to its original\'s op arrays before any test ran,%sand it declares what its original declares, so no test can fail on it (ADR-0130).', "\n"),
    ])->and($proven->programs)->toBe(['differs' => str_repeat('b', 64), 'first' => $alike, 'declares' => $alike, 'second' => $alike])
        ->and($printer->printed)->toBe([$original, $mutant('$a + 1', '1 + $a'), $mutant('int $a', 'int $b'), $mutant('+ 1', '+ 0 + 1')]);
});

it('proves nothing and starts no PHP for no mutants, and proves nothing where the extension did not load', function (): void {
    $php = FakePhp::printing("-\n");
    $proving = new ProvenBeforeRun(new OpArrays(sprintf('%s/php', $php), '/nowhere/outbreak.so', sprintf('%s/programs', $php), 5.0), new PrintedDeclarations());
    $none = $proving->proven([]);
    $started = is_file(sprintf('%s/arguments.txt', $php));
    $program = Contents::of("<?php\necho 1;\n");

    expect([$none, $started, $proving->proven([['first', $program, $program]])])
        ->toEqual([new BeforeRunProofs([], []), false, new BeforeRunProofs([], [])]);
});
