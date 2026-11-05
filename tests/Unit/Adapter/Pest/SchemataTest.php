<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    SchemaSwitch::off();
    Scratch::sweep();
});

/**
 * The first node of a class in code, read as Pest's generator reads it.
 *
 * @template T of Node
 *
 * @param  class-string<T> $class
 * @return T
 */
function generatedNode(string $code, string $class): Node
{
    $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor())->traverse($statements);
    $found = new NodeFinder()->findFirstInstanceOf($statements, $class);

    return $found instanceof $class ? $found : throw new LogicException(sprintf('No %s in the code.', $class));
}

it('writes a file\'s schema beside the folder of copies once its mutants are made, and tells each switched mutant its schema and id', function (): void {
    $root = Scratch::directory();
    $code = "<?php\n\nreturn 1 + 2;\n";
    $plus = generatedNode($code, Plus::class);
    $first = Mutations::mutation('/p/src/Money.php', 'first', 3);
    $second = Mutations::mutation('/p/src/Money.php', 'second', 3);

    Schemata::sited($first, $plus, new Minus($plus->left, $plus->right));
    Schemata::sited($second, $plus, replacement: false);
    Schemata::written('', $code, sprintf('%s/mutations', $root));
    $told = Schemata::variablesOf($first);
    $schema = $told[GateVariable::Schema->value];

    expect($told)->toBe([GateVariable::Schema->value => $schema, GateVariable::SchemaOn->value => '1'])
        ->and(dirname($schema))->toBe(sprintf('%s/mutations/../schemata', $root))
        ->and(file_get_contents($schema))->toContain('? (1 - 2) : (1 + 2)')
        ->and(Schemata::variablesOf($second))->toBe([])
        ->and(Schemata::variablesOf(Mutations::mutation('/p/src/Tax.php', 'other', 3)))->toBe([]);
});

it('writes nothing for a file whose mutants no schema can switch, and forgets a file\'s mutants once written', function (): void {
    $root = Scratch::directory();
    $code = "<?php\n\nclass A { public function f() {} }\n";
    $mutation = Mutations::mutation('/p/src/A.php', 'method', 3);

    Schemata::sited($mutation, generatedNode($code, ClassMethod::class), replacement: false);
    Schemata::written('/p/src/A.php', $code, sprintf('%s/mutations', $root));
    Schemata::written('/p/src/A.php', $code, sprintf('%s/mutations', $root));

    expect(is_dir(sprintf('%s/schemata', $root)))->toBeFalse()
        ->and(Schemata::variablesOf($mutation))->toBe([]);
});

it('serves the schema a mutant\'s run was told, its switch in force, and its own copy where it was told none it can serve', function (string|false $schema, string|false $on, bool $served): void {
    $file = sprintf('%s/schema.php', Scratch::directory());
    file_put_contents($file, '<?php');
    putenv(sprintf('%s=%s', GateVariable::Schema->value, $schema === 'there' ? $file : $schema));
    putenv(sprintf('%s=%s', GateVariable::SchemaOn->value, $on));

    if ($schema === false) {
        putenv(GateVariable::Schema->value);
    }

    if ($on === false) {
        putenv(GateVariable::SchemaOn->value);
    }

    expect(Schemata::inForce('/copy.php'))->toBe($served ? $file : '/copy.php')
        ->and(SchemaSwitch::is(7))->toBe($served);
})->with([
    'a schema there and an id' => ['there', '7', true],
    'no schema' => [false, '7', false],
    'a schema not there' => ['/nowhere/schema.php', '7', false],
    'no id' => ['there', false, false],
    'an id of nought' => ['there', '0', false],
]);

it('asks for schemata only where the gate says so', function (): void {
    $off = Schemata::areOn();
    putenv(sprintf('%s=1', GateVariable::Schemata->value));

    expect([$off, Schemata::areOn()])->toBe([false, true]);
});
