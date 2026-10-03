<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridged;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus as PestPlusToMinus;
use Pest\Mutate\Mutators\String\UnwrapWordwrap;
use Pest\Mutate\Support\MutatorMap;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\NodeVisitor;

it('offers its mutator only a node it changes, and puts the change in place as Pest does', function (): void {
    $plus = new Plus(new Variable('a'), new Variable('b'), ['origNode' => new Plus(new Variable('a'), new Variable('b'))]);
    $minus = new Minus(new Variable('a'), new Variable('b'));
    $changed = Bridged::mutate(new PlusToMinus(), $plus);

    expect(Bridged::can(new PlusToMinus(), $plus))->toBeTrue()
        ->and(Bridged::can(new PlusToMinus(), $minus))->toBeFalse()
        ->and($changed)->toBeInstanceOf(Minus::class)
        ->and($changed instanceof Minus ? $changed->getAttributes() : [])->not->toHaveKey('origNode')
        ->and(Bridged::mutate(new PlusToMinus(), $minus))->toBe($minus)
        ->and(Bridged::mutate(new RemoveEcho(), new Echo_([])))->toBe(NodeVisitor::REMOVE_NODE);
});

it('adds a bridge to Pest\'s map of mutators by node class, and names its mutants by its mutator\'s name', function (): void {
    try {
        Bridged::register(UnwrapWordwrap::class, 'acme/UnwrapWordwrap', [Plus::class, Variable::class]);
        $map = MutatorMap::get();

        expect($map[Plus::class] ?? [])->toContain(UnwrapWordwrap::class, PestPlusToMinus::class)
            ->and($map[Variable::class] ?? [])->toBe([UnwrapWordwrap::class])
            ->and(Bridged::nameOf(UnwrapWordwrap::class))->toBe('acme/UnwrapWordwrap')
            ->and(Bridged::nameOf(PestPlusToMinus::class))->toBe(PestPlusToMinus::class);
    } finally {
        MutatorMap::$map = null;
    }
});

it('loads the file of bridges a run names, and nothing where it names none or none is there', function (): void {
    $file = sprintf('%s/bridges.php', Scratch::untilExit());
    file_put_contents($file, "<?php\n\nfunction bridgedLoadedTheBridges(): string\n{\n    return 'loaded';\n}\n");

    Bridged::load(bridges: false);
    Bridged::load('');
    Bridged::load(sprintf('%s/absent.php', dirname($file)));

    expect(function_exists('bridgedLoadedTheBridges'))->toBeFalse();

    Bridged::load($file);

    expect(function_exists('bridgedLoadedTheBridges'))->toBeTrue();
});
