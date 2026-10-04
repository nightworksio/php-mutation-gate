<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use function array_any;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Ancestors;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\ResolvedName;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Calls;
use NightWorksIO\MutationGateLaravel\Facade;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\TraitUse;

use function str_ends_with;

/**
 * `$this->authorize(…)` in a class that authorizes requests, and `Gate::authorize(…)`, are removed.
 *
 * A class authorizes requests where it uses Laravel's `AuthorizesRequests`, or
 * extends a controller, as an app's base controller does.
 */
final readonly class RemoveAuthorize implements Mutator
{
    private const string AUTHORIZE = 'authorize';

    /** The trait that gives a class `authorize()`. */
    private const string AUTHORIZES = 'Illuminate\\Foundation\\Auth\\Access\\AuthorizesRequests';

    /** How the name of a controller a class extends ends. */
    private const string CONTROLLER = 'Controller';

    public function name(): MutatorName
    {
        return LaravelSet::mutator('RemoveAuthorize');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::RemovedCall;
    }

    public function tags(): Tags
    {
        return Tags::of(Tag::security());
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Expression::class);
    }

    public function mutate(Node $node): Removal|Unchanged
    {
        return $node instanceof Expression
            && (Calls::onFacade($node->expr, Facade::Gate, self::AUTHORIZE)
                || (Calls::onThis($node->expr, self::AUTHORIZE) && $this->authorizes($node)))
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test checks that the action is refused when the policy says no.');
    }

    /** Whether the class around a statement uses `AuthorizesRequests` or extends a controller. */
    private function authorizes(Expression $statement): bool
    {
        $class = Ancestors::nearest($statement, Class_::class);

        return $class instanceof Class_
            && (($class->extends instanceof Name && str_ends_with(ResolvedName::of($class->extends), self::CONTROLLER))
                || array_any(
                    $class->getTraitUses(),
                    static fn(TraitUse $use): bool => array_any(
                        $use->traits,
                        static fn(Name $trait): bool => ResolvedName::of($trait) === self::AUTHORIZES,
                    ),
                ));
    }
}
