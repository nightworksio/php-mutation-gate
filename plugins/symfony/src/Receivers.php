<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSymfony;

use function array_any;
use function in_array;
use function is_string;
use function mb_strtolower;

use NightWorksIO\MutationGate\Mutator\Ancestors;
use NightWorksIO\MutationGate\Mutator\Receiver;
use NightWorksIO\MutationGate\Mutator\ResolvedName;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;

use function str_ends_with;

/**
 * What a call is made on, read from the code around it: an entity manager by
 * the name an app gives it, and a message bus by the type its parameter or
 * property declares.
 */
final readonly class Receivers
{
    /** The names an app gives an entity manager whole, in lower case. */
    private const array MANAGERS = ['em', 'manager'];

    /** How the longer names of an entity manager end, in lower case. */
    private const array MANAGER_ENDINGS = ['entitymanager', 'objectmanager'];

    /** The methods that hand out an entity manager, as Doctrine's registry does. */
    private const array HANDING_OUT = ['getManager', 'getEntityManager'];

    private const string MESSAGE_BUS = 'Symfony\\Component\\Messenger\\MessageBusInterface';

    /** Whether a receiver is an entity manager: a variable or a property of `$this` by its name, or one handed out. */
    public static function isEntityManager(Expr $receiver): bool
    {
        $name = mb_strtolower(self::nameOf($receiver));

        return ($receiver instanceof MethodCall && Calls::named($receiver->name, ...self::HANDING_OUT))
            || in_array($name, self::MANAGERS, strict: true)
            || array_any(self::MANAGER_ENDINGS, static fn(string $ending): bool => str_ends_with($name, $ending));
    }

    /**
     * Whether a receiver is a message bus: a parameter of the function around
     * the call, or a property of `$this`, declared a `MessageBusInterface`.
     */
    public static function isMessageBus(Expr $receiver): bool
    {
        $name = self::nameOf($receiver);
        $function = Ancestors::nearest($receiver, FunctionLike::class);
        $class = Ancestors::nearest($receiver, Class_::class);

        return match (true) {
            $receiver instanceof Variable => $function instanceof FunctionLike && self::declares($function, $name),
            default => $class instanceof Class_ && self::holds($class, $name),
        };
    }

    /** The name of a variable, or of a property of `$this`; none for anything else. */
    private static function nameOf(Expr $receiver): string
    {
        return match (true) {
            $receiver instanceof Variable && is_string($receiver->name) => $receiver->name,
            $receiver instanceof PropertyFetch
                && Receiver::isThis($receiver->var)
                && $receiver->name instanceof Identifier => $receiver->name->toString(),
            default => '',
        };
    }

    /** Whether a function declares a parameter of this name a message bus. */
    private static function declares(FunctionLike $function, string $name): bool
    {
        return array_any(
            $function->getParams(),
            static fn(Param $param): bool => $param->var instanceof Variable
                && $param->var->name === $name
                && $param->type instanceof Node
                && self::isBus($param->type),
        );
    }

    /** Whether a class holds a property of this name declared a message bus, its constructor's promoted ones too. */
    private static function holds(Class_ $class, string $name): bool
    {
        $constructor = $class->getMethod('__construct');

        return array_any(
            $class->getProperties(),
            static fn(Property $property): bool => $property->type instanceof Node
                && self::isBus($property->type)
                && array_any(
                    $property->props,
                    static fn(PropertyItem $item): bool => $item->name->toString() === $name,
                ),
        ) || ($constructor instanceof ClassMethod && array_any(
            $constructor->getParams(),
            static fn(Param $param): bool => $param->isPromoted()
                && $param->var instanceof Variable
                && $param->var->name === $name
                && $param->type instanceof Node
                && self::isBus($param->type),
        ));
    }

    /** Whether a declared type is a message bus, nullable or not. */
    private static function isBus(Node $type): bool
    {
        $named = $type instanceof NullableType ? $type->type : $type;

        return $named instanceof Name && ResolvedName::of($named) === self::MESSAGE_BUS;
    }
}
