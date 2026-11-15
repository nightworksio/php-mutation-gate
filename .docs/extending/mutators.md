# Mutators

A mutator is a change the gate makes to code. It is written once, against
php-parser 5 and the gate's SDK, and runs under every runner through a bridge
the gate writes for it: beside Pest's own mutators, beside Infection's, and in
the gate's own engine for the native runners
([ADR-0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md)).
Mutators are registered in sets, and a config turns a set on with
`mutators.sets`.

## A mutator

A mutator implements `NightWorksIO\MutationGate\Mutator\Mutator`, is
constructed with no arguments, makes at most one change per node, and never
changes the node it is given:

| Method | What it answers |
|--------|-----------------|
| `name()` | Its `MutatorName`, `<set>/<Name>`, such as `laravel/GateAllowsToTrue`, which reports, ids and ignores write |
| `family()` | The `MutatorFamily` whose hint a survivor gets where it has none of its own: `Boundary`, `Condition`, `Logical`, `Arithmetic`, `ReturnValue`, `RemovedCall`, `Literal`, `Collection`, `Exception`, `Unwrap` or `Visibility` |
| `tags()` | What it is about: `Tags::of(Tag::security())` holds its mutants to the security floor too |
| `handles()` | The node classes it looks at; no other node is offered to it, and a file that holds none of them is skipped, so a narrow list keeps a run fast |
| `mutate(Node)` | The one change: a new `Node`, a `Removal` of a statement or of a list's item, or `Unchanged::node()` |
| `hint()` | `Hint::that(…)`, the sentence its survivors get, or `FamilyHint`, its family's |

Each node it is offered has its names resolved, in a `resolvedName`
attribute, and its parent in the `parent` attribute, under every runner.

```php
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Literals;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;

final readonly class HashEqualsToTrue implements Mutator
{
    public function name(): MutatorName
    {
        return MutatorName::of('acme', 'HashEqualsToTrue');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Condition;
    }

    public function tags(): Tags
    {
        return Tags::of(Tag::security());
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(FuncCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof FuncCall && $node->name instanceof Name && $node->name->toLowerString() === 'hash_equals'
            ? Literals::true()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('Add a test that checks that a wrong token is refused.');
    }
}
```

A mutator whose change others also make implements `SameChange`, and stands
down wherever one of them runs. One whose mutant only a test that reads its
file can kill implements `PinnedBySource`, and names what that test must find,
which `stub` writes the test from.

## A set

An extension registers its mutators as a set, by their classes:

```php
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Mutator\MutatorSet;

public function extend(Extensions $extensions): Extensions
{
    return $extensions->withMutators(Name::of('acme'), MutatorSet::of(HashEqualsToTrue::class));
}
```

A project turns it on with `"mutators": {"sets": ["acme"]}`, and a single
mutator off with `mutators.except`. A preset can turn a set on too.
Installing a package never changes a score by itself.

## Testing a mutator

`NightWorksIO\MutationGate\Mutator\Testing\Mutates` shows a mutator's changes
to a snippet of PHP as each runner's bridge makes them, without either runner
installed: `underPest()` gives one change per node the mutator handles,
anywhere in the snippet, which the gate's own engine makes too, and
`underInfection()` those in a class method or on its signature, the only nodes
Infection offers. Each change is the diff of the line it made:

```php
$mutates = Mutates::with(new HashEqualsToTrue(), <<<'PHP'
    <?php
    final class Login
    {
        public function check($known, $given)
        {
            return hash_equals($known, $given);
        }
    }
    PHP);

expect(iterator_to_array($mutates->underPest(), preserve_keys: false))
    ->toBe(["-        return hash_equals(\$known, \$given);\n+        return true;"]);
```

The first-party sets under
[`plugins/`](https://github.com/nightworksio/php-mutation-gate/tree/main/plugins)
are written this way, each mutator with its test.
