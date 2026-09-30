<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToPlus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;

function ledger(): string
{
    return <<<'CODE'
        <?php

        function total($a, $b)
        {
            return $a + $b;
        }

        final class Ledger
        {
            public function add($a, $b)
            {
                echo 'adding';

                return $a + $b;
            }
        }
        CODE;
}

/** @return list<MadeMutant> */
function made(MadeMutants|CannotJudge $mutants): array
{
    return $mutants instanceof MadeMutants ? iterator_to_array($mutants, preserve_keys: false) : [];
}

it('makes a mutant of every node a mutator handles, anywhere in the file, with its location, mutation and file', function (): void {
    $file = Path::of('src/Ledger.php');
    [$first] = made(Engine::with(new PlusToMinus())->mutantsOf($file, Contents::of(ledger())));
    $diff = "@@ @@\n-    return \$a + \$b;\n+    return \$a - \$b;";

    expect($first->id())->toEqual(MutantId::hash($file, 'acme/PlusToMinus', $diff, 0))
        ->and($first->location()->file())->toEqual($file)
        ->and($first->location()->start()->number())->toBe(5)
        ->and($first->location()->end())->toEqual($first->location()->start())
        ->and($first->mutation()->mutator())->toBe('acme/PlusToMinus')
        ->and($first->mutation()->family())->toBe(MutatorFamily::Arithmetic)
        ->and($first->mutation()->diff())->toBe($diff)
        ->and($first->mutated()->text())->toBe(str_replace("    return \$a + \$b;\n}", "    return \$a - \$b;\n}", ledger()));
});

it('orders the mutants of every mutator by the line each starts on', function (): void {
    $mutants = made(Engine::with(new PlusToMinus(), new RemoveEcho())->mutantsOf(Path::of('src/Ledger.php'), Contents::of(ledger())));

    expect(array_map(static fn(MadeMutant $mutant): array => [
        $mutant->mutation()->mutator(),
        $mutant->location()->start()->number(),
    ], $mutants))->toBe([
        ['acme/PlusToMinus', 5],
        ['acme/RemoveEcho', 12],
        ['acme/PlusToMinus', 14],
    ]);
});

it('removes a statement a mutator removes, leaving no empty statement in its place', function (): void {
    [$removed] = made(Engine::with(new RemoveEcho())->mutantsOf(Path::of('src/Ledger.php'), Contents::of(ledger())));

    expect($removed->mutation()->diff())->toBe("@@ @@\n-        echo 'adding';\n-")
        ->and($removed->mutated()->text())->not->toContain('echo');
});

it('counts the mutants before it that share its mutator and its diff, whitespace aside, in its id', function (): void {
    $file = Path::of('src/Ledger.php');
    [$first, $second] = made(Engine::with(new PlusToMinus())->mutantsOf($file, Contents::of(ledger())));

    expect($second->id())->toEqual(MutantId::hash($file, 'acme/PlusToMinus', $second->mutation()->diff(), 1))
        ->and($second->id())->not->toEqual($first->id());
});

it('makes no mutant of a change that prints the same code', function (): void {
    expect(Engine::with(new PlusToPlus())->mutantsOf(Path::of('src/Ledger.php'), Contents::of(ledger())))->toHaveCount(0);
});

it('cannot judge a file that does not parse, saying which and why', function (): void {
    $outcome = Engine::with(new PlusToMinus())->mutantsOf(Path::of('src/Broken.php'), Contents::of('<?php return 1 +;'));

    expect($outcome)->toBeInstanceOf(CannotJudge::class)
        ->and($outcome instanceof CannotJudge ? $outcome->why() : '')
        ->toBe("src/Broken.php does not parse, so no mutant of it can be made: Syntax error, unexpected ';' on line 1");
});
