<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridged;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Adapter\Pest\ServedClasses;
use NightWorksIO\MutationGate\Tests\Fakes\PinnedPestMutatorFake;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedBox;
use Pest\Mutate\Mutation;
use Pest\Mutate\Support\MutatorMap;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function (): void {
    GateEnvironment::clear();

    foreach (new ReflectionClass(Schemata::class)->getProperties(ReflectionProperty::IS_STATIC) as $property) {
        $property->setValue(null, $property->getDefaultValue());
    }
});

afterEach(function (): void {
    GateEnvironment::restore();
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

    $inForce = Schemata::inForce('/original.php', '/copy.php');
    $switched = SchemaSwitch::is('schema', 7);
    SchemaSwitch::off('schema');

    expect($inForce)->toBe($served ? $file : '/copy.php')
        ->and($switched)->toBe($served);
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

it('tells a mutant whose mutator is pinned by source no schema, so its run reads the copy its test reads', function (): void {
    $root = Scratch::directory();
    $code = "<?php\n\nreturn 1 + 2;\n";
    $plus = generatedNode($code, Plus::class);
    $pinned = new Mutation(new SplFileInfo('/p/src/Pinned.php', '', ''), 'pinned', PinnedPestMutatorFake::class, 3, 3, '', '/nowhere');
    $plain = Mutations::mutation('/p/src/Pinned.php', 'plain', 3);

    try {
        Bridged::register(PinnedPestMutatorFake::class, 'fake/Pinned', [], pinnedBySource: true);
        Schemata::sited($pinned, $plus, new Minus($plus->left, $plus->right));
        Schemata::sited($plain, $plus, new Minus($plus->left, $plus->right));
        Schemata::written('', $code, sprintf('%s/mutations', $root));

        expect(Schemata::variablesOf($pinned))->toBe([])
            ->and(Schemata::variablesOf($plain))->toHaveKey(GateVariable::Schema->value);
    } finally {
        MutatorMap::$map = null;
    }
});

it('leaves each file\'s schema in a warm directory, which a worker serves in the file\'s place, and a child of it is served nothing more', function (): void {
    $box = ServedBox::registered(body: 'public static function size(): int { return 1 + 1; }');
    $root = dirname($box->file, 2);
    $code = (string) file_get_contents($box->file);
    $plus = generatedNode($code, Plus::class);
    $mutation = Mutations::mutation($box->file, 'box', 7);
    $warm = sprintf('%s/warm', $root);
    mkdir($warm);

    try {
        Schemata::sited($mutation, $plus, new Minus($plus->left, $plus->right));
        Schemata::written($box->file, $code, sprintf('%s/mutations', $root));
        $schema = Schemata::variablesOf($mutation)[GateVariable::Schema->value];
        Schemata::leftIn($warm);
        $left = json_decode((string) file_get_contents(sprintf('%s/schemata.json', $warm)), associative: true);
        Schemata::servedIn($warm);
        putenv(sprintf('%s=%s', GateVariable::Schema->value, $schema));
        putenv(sprintf('%s=1', GateVariable::SchemaOn->value));
        $served = Schemata::inForce($box->file, $box->copy);
        $mutated = $box->called('size');
        SchemaSwitch::off(basename($schema, '.php'));

        expect($left)->toHaveKey($box->file, $schema)
            ->and(ServedClasses::serves($box->file, $schema))->toBeTrue()
            ->and($served)->toBe('')
            ->and([$mutated, $box->called('size')])->toBe(['0', '2']);
    } finally {
        ServedClasses::forget($box->file);
        $box->loader->unregister();
    }
});

it('serves nothing from a warm directory that names no schema, or names them in a shape it cannot read', function (string $left): void {
    $warm = Scratch::directory();
    file_put_contents(sprintf('%s/schemata.json', $warm), $left);

    Schemata::servedIn($warm);
    Schemata::servedIn(sprintf('%s/none', $warm));

    expect(ServedClasses::inPlaceOf(sprintf('%s/Box.php', $warm)))->toBe(sprintf('%s/Box.php', $warm));
})->with([
    'no schema' => ['{}'],
    'a schema by a list' => ['["x"]'],
    'a schema that is no text' => ['{"/p/Box.php": 1}'],
]);

it('numbers the mutants of a file it switches from one, in the order Pest made them, and from one again once the file is written', function (): void {
    $root = Scratch::directory();
    $code = "<?php\n\nreturn 1 + 2;\n";
    $plus = generatedNode($code, Plus::class);
    $first = Mutations::mutation('/p/src/Money.php', 'first', 3);
    $second = Mutations::mutation('/p/src/Money.php', 'second', 3);
    $later = Mutations::mutation('/p/src/Money.php', 'later', 3);
    $on = static fn(Mutation $mutation): string => Schemata::variablesOf($mutation)[GateVariable::SchemaOn->value] ?? '';
    mkdir(sprintf('%s/mutations', $root));

    Schemata::sited($first, $plus, new Minus($plus->left, $plus->right));
    Schemata::sited($second, $plus, new Mul($plus->left, $plus->right));
    Schemata::written('', $code, sprintf('%s/mutations', $root));
    Schemata::sited($later, $plus, new Minus($plus->left, $plus->right));
    Schemata::written('', $code, sprintf('%s/mutations', $root));

    expect([$on($first), $on($second), $on($later)])->toBe(['1', '2', '1']);
});

it('switches a mutant on in its own file\'s schema alone, though another file\'s mutant has its id', function (): void {
    $root = Scratch::directory();
    mkdir(sprintf('%s/mutations', $root));
    $code = "<?php\n\nreturn 1 + 2;\n";
    $schemata = [];

    foreach (['Money', 'Tax'] as $name) {
        $file = sprintf('%s/%s.php', $root, $name);
        file_put_contents($file, $code);
        $plus = generatedNode($code, Plus::class);
        $mutation = Mutations::mutation($file, $name, 3);
        Schemata::sited($mutation, $plus, new Minus($plus->left, $plus->right));
        Schemata::written((string) realpath($file), $code, sprintf('%s/mutations', $root));
        $schemata[] = Schemata::variablesOf($mutation);
    }

    [$money, $tax] = array_map(static fn(array $told): string => $told[GateVariable::Schema->value] ?? '', $schemata);
    SchemaSwitch::on(basename($money, '.php'), 1);

    try {
        $returned = [include $money, include $tax];
    } finally {
        SchemaSwitch::off(basename($money, '.php'));
    }

    expect(array_map(static fn(array $told): string => $told[GateVariable::SchemaOn->value] ?? '', $schemata))->toBe(['1', '1'])
        ->and($returned)->toBe([-1, 3]);
});
