<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSymfony\Mutators;

use function is_string;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Literals;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Calls;
use NightWorksIO\MutationGateSymfony\SymfonySet;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;

use function str_ends_with;

/**
 * `$form->isValid()` becomes `true`.
 *
 * A form is told by its name: a variable or a property of `$this` named `form`,
 * or whose name ends in `Form`.
 */
final readonly class FormValidToTrue implements Mutator
{
    private const string FORM = 'form';

    /** How the longer names of a form end. */
    private const string FORM_ENDING = 'Form';

    public function name(): MutatorName
    {
        return SymfonySet::mutator('FormValidToTrue');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Condition;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(MethodCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof MethodCall && Calls::named($node->name, 'isValid') && $this->isForm($node->var)
            ? Literals::true()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test submits invalid data and checks that the form refuses it.');
    }

    /** Whether a receiver is a form, by its name. */
    private function isForm(Expr $receiver): bool
    {
        $name = match (true) {
            $receiver instanceof Variable && is_string($receiver->name) => $receiver->name,
            $receiver instanceof PropertyFetch && $receiver->name instanceof Identifier => $receiver->name->toString(),
            default => '',
        };

        return $name === self::FORM || str_ends_with($name, self::FORM_ENDING);
    }
}
