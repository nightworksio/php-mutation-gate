<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridged;
use NightWorksIO\MutationGate\Adapter\Pest\Bridges;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\ParserNodes;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinusToo;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapHtmlentities;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapHtmlspecialchars;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapShellEscape;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapStripTags;
use Pest\Mutate\Contracts\Mutator;
use Pest\Mutate\Mutators\Arithmetic\MinusToPlus;
use Pest\Mutate\Mutators\Sets\DefaultSet;
use Pest\Mutate\Support\MutatorMap;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\NodeVisitor;

const PEST_BRIDGE = 'NightWorksIO\\MutationGateBridge\\Pest\\NightWorksIO\\MutationGate\\Tests\\Support\\Mutators\\PlusToMinus';

/** The bridges to the test's two mutators. */
function pestBridges(): Bridges
{
    return Bridges::to(Enabled::of(MutatorSet::of(PlusToMinus::class, RemoveEcho::class)));
}

it('names nothing for a run of every mutator with no bridge, so Pest applies its own list', function (): void {
    expect(new Bridges()->applying(Mutators::all()))->toBe([])
        ->and(new Bridges()->isEmpty())->toBeTrue()
        ->and(pestBridges()->isEmpty())->toBeFalse();
});

it('names Pest\'s default set beside every bridge for a run of every mutator', function (): void {
    expect(pestBridges()->applying(Mutators::all()))->toBe([
        DefaultSet::class,
        PEST_BRIDGE,
        'NightWorksIO\\MutationGateBridge\\Pest\\NightWorksIO\\MutationGate\\Tests\\Support\\Mutators\\RemoveEcho',
    ]);
});

it('bridges no mutator that stands down beside Pest\'s default set, as the security set\'s HTML unwraps do', function (): void {
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(
        PlusToMinusToo::class,
        UnwrapHtmlspecialchars::class,
        UnwrapHtmlentities::class,
        UnwrapStripTags::class,
        UnwrapShellEscape::class,
    )));

    expect($bridges->applying(Mutators::all()))->toBe([
        DefaultSet::class,
        'NightWorksIO\\MutationGateBridge\\Pest\\NightWorksIO\\MutationGateSecurity\\Mutators\\UnwrapShellEscape',
    ]);
});

it('names a bridged mutator by its bridge and any other as it is named, for a run of some', function (): void {
    expect(pestBridges()->applying(Mutators::named('acme/PlusToMinus', MinusToPlus::class)))
        ->toBe([PEST_BRIDGE, MinusToPlus::class]);
});

it('gives a bridged mutant its mutator\'s own hint, and none where the mutator uses its family\'s or is Pest\'s', function (): void {
    expect(pestBridges()->hintOf('acme/RemoveEcho'))->toBe('No test checks what is printed.')
        ->and(pestBridges()->hintOf('acme/PlusToMinus'))->toEqual(NotGiven::value())
        ->and(pestBridges()->hintOf(MinusToPlus::class))->toEqual(NotGiven::value());
});

it('gives a bridged mutant its mutator\'s own family, and any other Pest\'s', function (): void {
    expect(pestBridges()->familyOf('acme/RemoveEcho'))->toBe(MutatorFamily::RemovedCall)
        ->and(pestBridges()->familyOf(MinusToPlus::class))->toBe(MutatorFamily::Arithmetic);
});

it('writes bridges that Pest applies by its own contract, and registers each in Pest\'s map of mutators', function (): void {
    $file = sprintf('%s/bridges.php', Scratch::untilExit());
    file_put_contents($file, pestBridges()->written(ParserNodes::in(Tree::at('vendor'))));
    $echo = str_replace('PlusToMinus', 'RemoveEcho', PEST_BRIDGE);
    $called = static fn(string $bridge, string $method, mixed ...$arguments): mixed => new ReflectionMethod($bridge, $method)
        ->invoke(null, ...$arguments);

    try {
        Bridged::load($file);
        $plus = new Plus(new Variable('a'), new Variable('b'));

        expect(is_a(PEST_BRIDGE, Mutator::class, allow_string: true))->toBeTrue()
            ->and($called(PEST_BRIDGE, 'name'))->toBe('acme/PlusToMinus')
            ->and($called(PEST_BRIDGE, 'set'))->toBe('acme')
            ->and($called(PEST_BRIDGE, 'nodesToHandle'))->toBe([Plus::class])
            ->and($called(PEST_BRIDGE, 'can', $plus))->toBeTrue()
            ->and($called(PEST_BRIDGE, 'can', new Variable('a')))->toBeFalse()
            ->and($called(PEST_BRIDGE, 'mutate', $plus))->toBeInstanceOf(Minus::class)
            ->and($called($echo, 'mutate', new Echo_([])))->toBe(NodeVisitor::REMOVE_NODE)
            ->and(MutatorMap::get()[Plus::class] ?? [])->toContain(PEST_BRIDGE)
            ->and(MutatorMap::get()[Echo_::class] ?? [])->toContain($echo)
            ->and(Bridged::nameOf(PEST_BRIDGE))->toBe('acme/PlusToMinus');
    } finally {
        MutatorMap::$map = null;
    }
});

it('starts a run with the variable naming the bridges, written among the gate\'s files', function (): void {
    $root = Scratch::directory();
    $project = Project::at($root, Paths::of(Path::of('tests')), Workspace::root(), Path::of(Tree::at('vendor')));
    $command = Command::of('pest');
    $loading = pestBridges()->loading($project, $command);
    $file = sprintf('%s/.mutation-gate/mutators/pest/bridges.php', realpath($root));

    expect($loading)->toEqual($command->with(['MUTATION_GATE_MUTATORS' => $file]))
        ->and(is_file($file) ? (string) file_get_contents($file) : '')->toContain('acme/PlusToMinus')
        ->and(new Bridges()->loading($project, $command))->toBe($command);
});

it('refuses every run where Pest cannot make mutants with what the options name', function (): void {
    $project = Project::at(Scratch::directory(), Paths::none(), Workspace::root(), Path::of('vendor'));
    $why = CannotJudge::because('The pest runner cannot make mutants with stdClass, which is not a mutator.');

    expect(Bridges::refusing($why)->loading($project, Command::pest('pest', Withheld::standard())))->toBe($why);
});

it('cannot start a run where an earlier run\'s file of bridges cannot be replaced', function (): void {
    $root = (string) realpath(Scratch::directory());
    $file = sprintf('%s/.mutation-gate/mutators/pest/bridges.php', $root);
    mkdir($file, recursive: true);
    $project = Project::at($root, Paths::none(), Workspace::root(), Path::of('vendor'));

    expect(pestBridges()->loading($project, Command::of('pest')))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left %s, and the gate cannot remove it.',
        $file,
    )));
});
