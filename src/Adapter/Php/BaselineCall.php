<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use function is_string;
use function mb_strtolower;

use NightWorksIO\MutationGate\Config\Baseline;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\ResolvedName;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * The baseline a PHP config names, read from its code without running it
 * (ADR-0026, decision 3): the literal path of its first `Baseline::at()`
 * call, as the file's imports resolve the class. `migrate` never runs a
 * config, so a path built any other way is not read.
 */
final readonly class BaselineCall
{
    /** The literal path the config's first `Baseline::at()` call names, as written; none where it names none. */
    public static function in(string $code): string|NotGiven
    {
        $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($code, new Collecting()) ?? [];
        $resolved = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]))->traverse($statements);

        foreach (new NodeFinder()->findInstanceOf($resolved, StaticCall::class) as $call) {
            $path = self::named($call);

            if (is_string($path)) {
                return $path;
            }
        }

        return NotGiven::value();
    }

    private static function named(StaticCall $call): string|NotGiven
    {
        $class = $call->class instanceof Name ? $call->class->getAttribute(ResolvedName::ATTRIBUTE) : null;
        $first = $call->getArgs() === [] ? null : $call->getArgs()[0]->value;
        $isAt = $call->name instanceof Identifier && $call->name->toLowerString() === 'at';

        $isBaseline = $class instanceof Name && $class->toLowerString() === mb_strtolower(Baseline::class);

        return $isBaseline && $isAt && $first instanceof String_ ? $first->value : NotGiven::value();
    }
}
