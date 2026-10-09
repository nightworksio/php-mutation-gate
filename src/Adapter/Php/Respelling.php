<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Migration\BuilderCall;
use NightWorksIO\MutationGate\Core\Migration\MapValue;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Move;
use NightWorksIO\MutationGate\Core\Migration\Rename;
use NightWorksIO\MutationGate\Core\Migration\Retired;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeVisitorAbstract;

use function sprintf;

/**
 * Each call a step retires, rewritten in place (ADR-0026, decision 3): a
 * rename or a move gives it the new spelling with the same arguments, its
 * class left as the file writes it where the class stays, a removal takes it
 * out of `with()`, and a value's change
 * rewrites its literal argument. A call it cannot rewrite is listed for a
 * hand edit, and so is, wherever a step names a spelling, a `with()`
 * argument that is no builder call, since only running it says what it
 * writes.
 */
final class Respelling extends NodeVisitorAbstract
{
    private const string LINE = 'line %d';

    private const string UNKNOWN
        = '`with()` is given what is no builder call, so migrate cannot tell what it writes: check it by hand';

    /** @var list<Problem> */
    private array $left = [];

    private readonly bool $spelt;

    public function __construct(private readonly Migrations $migrations)
    {
        $named = false;

        foreach ($migrations as $retired) {
            $named = $named || $retired->step()->spelling() instanceof Spelling;
        }

        $this->spelt = $named;
    }

    public function leaveNode(Node $node): Node
    {
        $retired = RetiredCall::of($node, $this->migrations);

        return match (true) {
            $retired instanceof Retired && ($node instanceof StaticCall || $node instanceof MethodCall)
                => $this->rewritten($node, $retired),
            $node instanceof MethodCall && RetiredCall::isWith($node) => $this->with($node),
            default => $node,
        };
    }

    /** The calls left for a hand edit, each at its line; nothing where none is left. */
    public function left(): Invalid|NotGiven
    {
        return $this->left === [] ? NotGiven::value() : Invalid::because(...$this->left);
    }

    private function rewritten(StaticCall|MethodCall $call, Retired $retired): Node
    {
        $step = $retired->step();
        $spelling = $step->spelling();
        $replacement = $spelling instanceof Spelling ? $spelling->replacement() : NotGiven::value();

        return match (true) {
            ($step instanceof Rename || $step instanceof Move) && $replacement instanceof BuilderCall
                => $this->respelt($call, $replacement),
            $step instanceof MapValue && Literals::mapped($call, $step) => $call,
            default => $this->byHand($call, $retired),
        };
    }

    private function respelt(StaticCall|MethodCall $call, BuilderCall $replacement): Node
    {
        $call->name = new Identifier($replacement->method());

        if ($call instanceof StaticCall && ! RetiredCall::isOfClass($call, $replacement)) {
            $call->class = new FullyQualified(RetiredCall::className($replacement));
        }

        return $call;
    }

    /** A `with()`, each of its arguments that is no builder call listed for a hand edit. */
    private function with(MethodCall $call): Node
    {
        foreach ($call->args as $argument) {
            $value = $argument instanceof Arg ? $argument->value : null;
            $built = $value instanceof StaticCall && RetiredCall::isBuilder($value);

            if ($this->spelt && ! $built) {
                $this->left[] = Problem::at(sprintf(self::LINE, $argument->getStartLine()), self::UNKNOWN);
            }
        }

        return $call;
    }

    private function byHand(StaticCall|MethodCall $call, Retired $retired): Node
    {
        $this->left[] = Problem::at(sprintf(self::LINE, RetiredCall::line($call)), $retired->byHand());

        return $call;
    }
}
