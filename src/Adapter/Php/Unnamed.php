<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Config\BuilderClasses;
use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\ShellExec;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;

use function sprintf;

/**
 * Why an expression of a config, other than an include, reaches what the gate
 * cannot name without running it (ADR-0005, decision 4): a function outside
 * those that read nothing, a class outside the gate's builder, a call by a
 * name held as it runs, `eval` or a shell command. Every other expression
 * reads nothing by itself.
 */
final readonly class Unnamed
{
    private const string CALLED = '`%s()`';

    private const string NAMED = '`%s`';

    private const string BY_NAME = 'a call by a name it holds';

    private const string EVAL = '`eval`';

    private const string SHELL = 'a shell command';

    private const string ANONYMOUS = '`new class`';

    /** What an expression reads that cannot be named, as a sentence says it; nothing where it reads nothing. */
    public function in(Expr $expression): string|NotGiven
    {
        return match (true) {
            $expression instanceof Eval_ => self::EVAL,
            $expression instanceof ShellExec => self::SHELL,
            $expression instanceof FuncCall => $this->called($expression),
            $expression instanceof MethodCall, $expression instanceof NullsafeMethodCall
                => $this->byName($expression->name),
            $expression instanceof StaticCall => $this->staticallyCalled($expression),
            $expression instanceof New_, $expression instanceof ClassConstFetch,
            $expression instanceof StaticPropertyFetch, $expression instanceof Instanceof_
                => $this->classIn($expression->class),
            default => NotGiven::value(),
        };
    }

    /** Why a call names its method as it runs; nothing where it names it as written. */
    private function byName(Identifier|Expr $name): string|NotGiven
    {
        return $name instanceof Identifier ? NotGiven::value() : self::BY_NAME;
    }

    /** Why a static call could read a file: its class is outside the builder, or it names its method as it runs. */
    private function staticallyCalled(StaticCall $call): string|NotGiven
    {
        $class = $this->classIn($call->class);

        return $class instanceof NotGiven ? $this->byName($call->name) : $class;
    }

    /** Why a function call could read a file: one outside those that read nothing, or one by a held name. */
    private function called(FuncCall $call): string|NotGiven
    {
        $name = $call->name instanceof Name ? $call->name->toString() : NotGiven::value();

        return match (true) {
            $name instanceof NotGiven => self::BY_NAME,
            QuietFunction::tryFrom(mb_strtolower($name)) instanceof QuietFunction
                => NotGiven::value(),
            default => sprintf(self::CALLED, $name),
        };
    }

    /** Why a class an expression names could read a file: any outside the gate's builder, or one named as it runs. */
    private function classIn(Name|Expr|Class_ $class): string|NotGiven
    {
        return match (true) {
            $class instanceof Name => BuilderClasses::holds($class->toString())
                ? NotGiven::value()
                : sprintf(self::NAMED, $class->toString()),
            $class instanceof Class_ => self::ANONYMOUS,
            default => self::BY_NAME,
        };
    }
}
