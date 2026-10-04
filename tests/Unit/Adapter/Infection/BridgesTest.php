<?php

declare(strict_types=1);

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use NightWorksIO\MutationGate\Adapter\Infection\Bridges;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinusToo;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\Variable;

const INFECTION_BRIDGE = 'NightWorksIO\\MutationGateBridge\\Infection\\NightWorksIO\\MutationGate\\Tests\\Support\\Mutators\\PlusToMinus';

/** The bridges to the test's two mutators. */
function infectionBridges(): Bridges
{
    return Bridges::to(Enabled::of(MutatorSet::of(PlusToMinus::class, RemoveEcho::class)));
}

it('turns each bridge on by its class, and names a bridged mutator by it', function (): void {
    expect(infectionBridges()->classes())->toBe([
        INFECTION_BRIDGE,
        'NightWorksIO\\MutationGateBridge\\Infection\\NightWorksIO\\MutationGate\\Tests\\Support\\Mutators\\RemoveEcho',
    ])
        ->and(infectionBridges()->keyOf('acme/PlusToMinus'))->toBe(INFECTION_BRIDGE)
        ->and(infectionBridges()->keyOf('Minus'))->toBe('Minus')
        ->and(infectionBridges()->isEmpty())->toBeFalse()
        ->and(new Bridges()->isEmpty())->toBeTrue();
});

it('gives a bridged mutant its mutator\'s own hint, and none where the mutator uses its family\'s or is Infection\'s', function (): void {
    expect(infectionBridges()->hintOf('acme/RemoveEcho'))->toBe('No test checks what is printed.')
        ->and(infectionBridges()->hintOf('acme/PlusToMinus'))->toEqual(NotGiven::value())
        ->and(infectionBridges()->hintOf('Minus'))->toEqual(NotGiven::value());
});

it('gives a bridged mutant its mutator\'s own family, and any other Infection\'s', function (): void {
    expect(infectionBridges()->familyOf('acme/RemoveEcho'))->toBe(MutatorFamily::RemovedCall)
        ->and(infectionBridges()->familyOf('Minus'))->toBe(MutatorFamily::Arithmetic);
});

it('writes bridges that Infection applies by its own contract, named by their mutators, then loads the project\'s bootstrap', function (): void {
    $directory = Scratch::untilExit();
    $bootstrap = sprintf('%s/bootstrap.php', $directory);
    file_put_contents($bootstrap, "<?php\n\nfunction infectionBridgesLoadedTheBootstrap(): bool\n{\n    return true;\n}\n");
    $file = sprintf('%s/bridges.php', $directory);
    file_put_contents($file, infectionBridges()->written($bootstrap));

    require_once $file;
    $bridge = infectionBridges()->classes()[0] ?? '';
    $mutator = is_a($bridge, Mutator::class, allow_string: true)
        ? new ReflectionClass($bridge)->newInstance()
        : $bridge;
    $definition = is_a($bridge, Mutator::class, allow_string: true)
        ? new ReflectionMethod($bridge, 'getDefinition')->invoke(null)
        : $bridge;
    $plus = new Plus(new Variable('a'), new Variable('b'));

    expect($bridge)->toBe(INFECTION_BRIDGE)
        ->and($mutator instanceof Mutator ? $mutator->getName() : $mutator)->toBe('acme/PlusToMinus')
        ->and($mutator instanceof Mutator && $mutator->canMutate($plus))->toBeTrue()
        ->and($mutator instanceof Mutator ? [...$mutator->mutate($plus)][0] ?? null : $mutator)
        ->toBeInstanceOf(Minus::class)
        ->and($definition instanceof Definition ? $definition->getDescription() : $definition)
        ->toBe('acme/PlusToMinus')
        ->and(function_exists('infectionBridgesLoadedTheBootstrap'))->toBeTrue()
        ->and(infectionBridges()->written(NotGiven::value()))->not->toContain('require_once');
});

it('says why Infection cannot make mutants with what the options name, and nothing where it can', function (): void {
    $why = CannotJudge::because('The infection runner cannot make mutants with stdClass, which is not a mutator.');

    expect(Bridges::refusing($why)->refusal())->toBe($why)
        ->and(Bridges::refusing($why)->isEmpty())->toBeTrue()
        ->and(infectionBridges()->refusal())->toEqual(NotGiven::value());
});

it('keeps each bridge but those to a mutator that stands down beside Infection\'s own, and keeps its refusal', function (): void {
    $why = CannotJudge::because('The infection runner cannot make mutants with stdClass, which is not a mutator.');
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(PlusToMinusToo::class, RemoveEcho::class)));

    expect($bridges->besides(NamedMutators::of('Plus'))->classes())->toBe([
        'NightWorksIO\\MutationGateBridge\\Infection\\NightWorksIO\\MutationGate\\Tests\\Support\\Mutators\\RemoveEcho',
    ])
        ->and($bridges->besides(NamedMutators::of('Minus'))->classes())->toBe($bridges->classes())
        ->and(Bridges::refusing($why)->besides(NamedMutators::of('Plus'))->refusal())->toBe($why);
});

it('runs the named mutators of Infection\'s own, and those bridged that do not stand down beside them', function (): void {
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(PlusToMinusToo::class, RemoveEcho::class)));
    $infection = NamedMutators::of('Plus', 'Minus');

    expect($bridges->runnable(Mutators::named('acme/PlusToMinusToo', 'acme/RemoveEcho', 'default/Plus', 'Minus'), $infection))
        ->toBe(['acme/PlusToMinusToo', 'acme/RemoveEcho', 'Minus'])
        ->and($bridges->runnable(Mutators::named('acme/PlusToMinusToo', 'Plus', PlusToMinus::class), $infection))
        ->toBe(['Plus']);
});
