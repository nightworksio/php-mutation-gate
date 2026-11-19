<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\Schema;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\Site;
use NightWorksIO\MutationGate\Tests\Support\Nodes;
use NightWorksIO\MutationGate\Tests\Support\Schemata;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Nop;

$holds = ['holds:src/Adapter/Pest/SchemaSwitch.php', 'holds:src/Mutator/Engine/Schema/SwitchCall.php'];

afterEach(function (): void {
    SchemaSwitch::unsplit();
    Scratch::sweep();
});

/** A loop whose body holds a value's site, an expression's site and a statement's site, each run five times. */
const GUARDED_CODE = "<?php\n\n\$total = 0;\n\nfor (\$i = 0; \$i < 5; \$i++) {\n    \$total = \$total + (\$i > 2 ? \$i * 2 : 1);\n    \$total++;\n}\n\nreturn \$total;\n";

/** The schema of the loop's three mutants, guarded or not, its pure sites taking both values. */
function guardedSchema(bool $guarded): Schema
{
    return Schemata::of(
        GUARDED_CODE,
        static fn(Nodes $nodes): array => [
            Site::of($nodes->nth(Greater::class), new GreaterOrEqual($nodes->nth(Greater::class)->left, $nodes->nth(Greater::class)->right), 1),
            Site::of($nodes->nth(Mul::class), new Plus($nodes->nth(Mul::class)->left, $nodes->nth(Mul::class)->right), 2),
            Site::of($nodes->nth(Expression::class, 2), new Nop(), 3),
        ],
        valuing: true,
        guarded: $guarded,
    );
}

/**
 * What the schema returns under a split stream that runs the mutant this id names, at every execution of its site,
 * and every other site's original, with each site it was asked about, in order; the guard, where the schema has one,
 * holds these ids of each file undecided.
 *
 * @param  array<string, list<int>> $guard
 * @return array{mixed, list<int>}
 */
function guardedRun(Schema $schema, int $runs, array $guard): array
{
    $asked = [];
    SchemaSwitch::splitting(
        static function (string $file, int $id) use (&$asked, $runs): bool {
            $asked[] = $id;

            return $id === $runs;
        },
        static function (string $file, int $id, mixed $value, Closure $mutated) use (&$asked, $runs): mixed {
            $asked[] = $id;

            return $id === $runs ? $mutated() : $value;
        },
    );
    SchemaSwitch::guarding($guard);
    $file = (string) tempnam(Scratch::directory(), 'guarded');
    file_put_contents($file, $schema->text());

    try {
        $returned = include $file;
        $sites = array_values(array_unique($asked));
        sort($sites);

        return [$returned, $sites];
    } finally {
        SchemaSwitch::unsplit();
    }
}

it('returns what the unguarded schema returns, whichever mutant a stream runs, and asks only of the sites still undecided', function (int $runs): void {
    [$unguarded, $everySite] = guardedRun(guardedSchema(guarded: false), $runs, [Schemata::KEY => [1, 2, 3]]);
    [$guarded, $live] = guardedRun(guardedSchema(guarded: true), $runs, [Schemata::KEY => $runs === 0 ? [] : [$runs]]);

    expect($guarded)->toBe($unguarded)
        ->and($everySite)->toBe([1, 2, 3])
        ->and($live)->toBe($runs === 0 ? [] : [$runs]);
})->with([
    'no mutant' => [0],
    'the comparison' => [1],
    'the product' => [2],
    'the increment' => [3],
])->group(...$holds);

it('calls every switch of a file the guard holds no key of, as an unguarded schema does', function (): void {
    expect(guardedRun(guardedSchema(guarded: true), 2, []))->toBe(guardedRun(guardedSchema(guarded: false), 2, []));
})->group(...$holds);

it('stops asking of a site once it is decided, and asks of every site while the guard is lifted', function (): void {
    SchemaSwitch::guarding([Schemata::KEY => [1, 2]]);
    SchemaSwitch::decided(Schemata::KEY, 1);
    $held = SchemaSwitch::$live;
    SchemaSwitch::lifting(lifted: true);
    $lifted = SchemaSwitch::$live;
    SchemaSwitch::decided(Schemata::KEY, 2);
    SchemaSwitch::lifting(lifted: false);

    expect([$held, $lifted, SchemaSwitch::$live])->toBe([[Schemata::KEY => [2 => true]], [], [Schemata::KEY => []]]);
})->group(...$holds);
