<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use function array_slice;
use function explode;
use function implode;
use function mb_strtolower;

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Core\Config\GateMethod;
use NightWorksIO\MutationGate\Core\Migration\BuilderCall;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Retired;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\ResolvedName;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;

use function sprintf;

/**
 * A call in a PHP config that a step retires (ADR-0026, decision 3): a
 * static call of a builder class, its name resolved through the file's
 * imports, or a method called along `Gate`'s chain.
 */
final readonly class RetiredCall
{
    /** Whether a call along the chain is `with()`, which takes settings built elsewhere. */
    public static function isWith(MethodCall $call): bool
    {
        return $call->name instanceof Identifier
            && $call->name->toLowerString() === mb_strtolower(GateMethod::With->value);
    }

    /** The step that retires this call, the first where several do; none where none does. */
    public static function of(Node $node, Migrations $migrations): Retired|NotGiven
    {
        foreach ($migrations as $retired) {
            $spelling = $retired->step()->spelling();

            if ($spelling instanceof Spelling && self::spells($node, $spelling->retired())) {
                return $retired;
            }
        }

        return NotGiven::value();
    }

    /** A builder class's name in full: `Reach` is `NightWorksIO\MutationGate\Config\Reach`. */
    public static function className(BuilderCall $call): string
    {
        return sprintf('%s\\%s', self::namespace(), $call->className());
    }

    /** The line a call's method is named on, which a call along the chain does not start on. */
    public static function line(StaticCall|MethodCall $call): int
    {
        return $call->name->getStartLine();
    }

    /** Whether a static call's class, as the file's imports resolve it, is a builder class. */
    public static function isBuilder(StaticCall $call): bool
    {
        $name = self::resolved($call);

        return $name instanceof Name && $name->slice(0, -1)?->toString() === self::namespace();
    }

    /** Whether a static call's class, as the file's imports resolve it, is the class of this builder call. */
    public static function isOfClass(StaticCall $node, BuilderCall $call): bool
    {
        $class = self::resolved($node);

        return $class instanceof Name && mb_strtolower($class->toString()) === mb_strtolower(self::className($call));
    }

    /** A static call's class in full: as the file's imports resolve it, or as a rewrite named it in full. */
    private static function resolved(StaticCall $call): Name|NotGiven
    {
        $class = $call->class;
        $resolved = $class instanceof Name ? $class->getAttribute(ResolvedName::ATTRIBUTE) : null;

        return match (true) {
            $resolved instanceof Name => $resolved,
            $class instanceof FullyQualified => $class,
            default => NotGiven::value(),
        };
    }

    /** The namespace of the config's builder classes. */
    private static function namespace(): string
    {
        return implode('\\', array_slice(explode('\\', Gate::class), 0, -1));
    }

    private static function spells(Node $node, BuilderCall $call): bool
    {
        return match (true) {
            $node instanceof StaticCall => ! $call->isOnTheChain() && self::calls($node, $call),
            $node instanceof MethodCall => $call->isOnTheChain() && self::named($node->name, $call),
            default => false,
        };
    }

    private static function calls(StaticCall $node, BuilderCall $call): bool
    {
        return self::isOfClass($node, $call) && self::named($node->name, $call);
    }

    private static function named(Node $name, BuilderCall $call): bool
    {
        return $name instanceof Identifier && $name->toLowerString() === mb_strtolower($call->method());
    }
}
