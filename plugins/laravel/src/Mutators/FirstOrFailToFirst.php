<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;

/**
 * `->firstOrFail()` becomes `->first()`, and `->findOrFail(…)` becomes
 * `->find(…)`, on any receiver, a model class included.
 */
final readonly class FirstOrFailToFirst implements Mutator
{
    /** Each method that fails where nothing is found, by its name in lower case, and the one that returns null. */
    private const array FORGIVING = ['firstorfail' => 'first', 'findorfail' => 'find'];

    public function name(): MutatorName
    {
        return LaravelSet::mutator('FirstOrFailToFirst');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Exception;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(MethodCall::class, StaticCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof MethodCall || $node instanceof StaticCall ? $this->forgiving($node) : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test asks for a record that does not exist and expects it not to be found.');
    }

    /** The call, of the method that returns null where the one it calls fails. */
    private function forgiving(MethodCall|StaticCall $call): Node|Unchanged
    {
        $name = $call->name instanceof Identifier ? $call->name->toLowerString() : '';

        if (! array_key_exists($name, self::FORGIVING)) {
            return Unchanged::node();
        }

        $forgiving = clone $call;
        $forgiving->name = new Identifier(self::FORGIVING[$name]);

        return $forgiving;
    }
}
