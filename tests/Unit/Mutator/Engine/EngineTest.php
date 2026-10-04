<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutants;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToPlus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGateDefault\DefaultExtension;

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

it('counts where each mutant starts, line by line, without making it', function (): void {
    $file = Path::of('src/Ledger.php');
    $sites = Engine::with(new PlusToMinus(), new RemoveEcho())->sitesOf($file, Contents::of(ledger()));

    expect($sites instanceof MutantSites ? [...$sites->linesOf($file)] : [])
        ->toEqual([Line::of(5), Line::of(12), Line::of(14)])
        ->and($sites instanceof MutantSites ? $sites->count() : 0)->toBe(3);
});

it('counts a change that prints the same code, of which it makes no mutant', function (): void {
    $file = Path::of('src/Ledger.php');

    expect(Engine::with(new PlusToPlus())->sitesOf($file, Contents::of(ledger())))
        ->toEqual(MutantSites::inFile($file, Line::of(5), Line::of(14)))
        ->and(made(Engine::with(new PlusToPlus())->mutantsOf($file, Contents::of(ledger()))))->toBe([]);
});

it('cannot count the mutants of a file that does not parse, saying which and why', function (): void {
    expect(Engine::with(new PlusToMinus())->sitesOf(Path::of('src/Broken.php'), Contents::of('<?php function (')))
        ->toBeInstanceOf(CannotJudge::class);
});

/** The engine over the default set, as the plan counts with it. */
function defaultEngine(): Engine
{
    $set = new DefaultExtension()->extend(new Extensions(Origin::of('nightworksio/mutation-gate')))
        ->registered(ExtensionPoint::MutatorSet, MutatorSet::defaultName());
    return Enabled::of($set instanceof MutatorSet ? $set : MutatorSet::of())->engine();
}

/**
 * The runner contracts' libraries: code written to be mutated, by both runners.
 *
 * @return list<string>
 */
function corpus(): array
{
    $files = [];

    foreach (['fixture', 'infection-fixture'] as $library) {
        $found = glob(sprintf('%s/Contract/Runner/%s/src/{,*/}*.php', dirname(__DIR__, 3), $library), GLOB_BRACE);
        $files = [...$files, ...($found === false ? [] : $found)];
    }

    sort($files);

    return $files;
}

/**
 * How many mutants start on each line, by line number.
 *
 * @return array<int, int>
 */
function perLine(MutantSites $sites, Path $file): array
{
    $counts = [];

    foreach ($sites->linesOf($file) as $line) {
        $counts[$line->number()] = $sites->countAt($file, $line);
    }

    return $counts;
}

it('counts, line by line, the mutants the engine makes of every file of the corpus', function (string $file): void {
    $path = Path::of(basename($file));
    $code = Contents::of((string) file_get_contents($file));
    $engine = defaultEngine();
    $sites = $engine->sitesOf($path, $code);
    $made = $engine->mutantsOf($path, $code);
    $starts = array_map(
        static fn(MadeMutant $mutant): Line => $mutant->location()->start(),
        $made instanceof MadeMutants ? iterator_to_array($made, preserve_keys: false) : [],
    );

    // No mutator of the default set prints the same code anywhere in the corpus, so the two agree exactly.
    expect($made)->toBeInstanceOf(MadeMutants::class)
        ->and($sites instanceof MutantSites ? perLine($sites, $path) : [])
        ->toBe(perLine(MutantSites::inFile($path, ...$starts), $path));
})->with(corpus());

it('has a corpus to agree over', function (): void {
    expect(count(corpus()))->toBeGreaterThan(20);
});
