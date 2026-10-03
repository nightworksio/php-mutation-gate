<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Collectors;

use function array_any;
use function array_filter;
use function array_key_exists;
use function array_values;

use NightWorksIO\MutationGate\PHPStan\Rules\OneHome\Written;
use NightWorksIO\MutationGate\PHPStan\Rules\OwnSource;
use NightWorksIO\MutationGate\PHPStan\Rules\SharedLiterals\Declaration;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;

use function sprintf;
use function var_export;

/**
 * D12 — every string literal a call under `src` hands a method declared
 * under `src`, as the method, the parameter, the literal as written, the
 * class that hands it, and the file and line that declare the method, for
 * NoSharedLiteralArgumentRule to group. The method is read through the
 * analyser's types, so an instance call counts as a static one does.
 *
 * A literal too plain to say anything (Written::isPlain()) is left out.
 *
 * @implements Collector<CallLike, list<array{method: string, parameter: string, literal: string, caller: string, file: string, line: int}>>
 */
final readonly class LiteralArguments implements Collector
{
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<array{method: string, parameter: string, literal: string, caller: string, file: string, line: int}> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! OwnSource::holds($scope->getFile()) || $node->isFirstClassCallable() || ! array_any($node->getArgs(), $this->isALiteral(...))) {
            return [];
        }

        $found = [];

        foreach ($this->called($node, $scope) as $method) {
            $class = $method->getDeclaringClass();
            $file = (string) $class->getFileName();
            $parameters = ParametersAcceptorSelector::selectFromArgs($scope, $node->getArgs(), $method->getVariants())->getParameters();

            foreach ($this->literalsIn(array_values($node->getArgs()), $parameters) as [$parameter, $literal]) {
                $found[] = [
                    'method' => sprintf('%s::%s', $class->getName(), $method->getName()),
                    'parameter' => $parameter,
                    'literal' => $literal,
                    'caller' => $scope->isInClass() ? $scope->getClassReflection()->getName() : $scope->getFile(),
                    'file' => $file,
                    'line' => Declaration::lineOf($class, $method->getName()),
                ];
            }
        }

        return $found;
    }

    /**
     * The method a call reaches, as the analyser types its receiver, where
     * it knows one and the package's own source declares it.
     *
     * @return list<ExtendedMethodReflection>
     */
    private function called(CallLike $call, Scope $scope): array
    {
        $reached = match (true) {
            $call instanceof MethodCall, $call instanceof NullsafeMethodCall => $call->name instanceof Identifier
                ? [$scope->getMethodReflection($scope->getType($call->var), $call->name->toString())]
                : [],
            $call instanceof StaticCall => $call->class instanceof Name && $call->name instanceof Identifier
                ? [$scope->getMethodReflection($scope->resolveTypeByName($call->class), $call->name->toString())]
                : [],
            $call instanceof New_ => $call->class instanceof Name ? [$scope->getMethodReflection($scope->getType($call), '__construct')] : [],
            default => [],
        };

        return array_filter(
            $reached,
            static fn(?ExtendedMethodReflection $method): bool => $method instanceof ExtendedMethodReflection
                && OwnSource::holds((string) $method->getDeclaringClass()->getFileName()),
        );
    }

    /**
     * Each string literal among the arguments, with the parameter it is handed to.
     *
     * @param list<Arg>                 $arguments
     * @param list<ParameterReflection> $parameters
     *
     * @return list<array{string, string}>
     */
    private function literalsIn(array $arguments, array $parameters): array
    {
        $literals = [];

        foreach (array_filter($arguments, $this->isALiteral(...)) as $position => $argument) {
            $written = var_export($argument->value->value, return: true);
            $parameter = $this->parameterOf($argument, $position, $parameters);

            if (! Written::isPlain($written) && $parameter !== '') {
                $literals[] = [$parameter, $written];
            }
        }

        return $literals;
    }

    /**
     * Whether an argument is a string literal.
     *
     * @phpstan-assert-if-true Arg&object{value: String_} $argument
     */
    private function isALiteral(Arg $argument): bool
    {
        return $argument->value instanceof String_;
    }

    /**
     * The name of the parameter an argument is handed to: the one it names,
     * the one at its position, or a variadic last one; or nothing.
     *
     * @param list<ParameterReflection> $parameters
     */
    private function parameterOf(Arg $argument, int $position, array $parameters): string
    {
        $variadic = array_values(array_filter($parameters, static fn(ParameterReflection $parameter): bool => $parameter->isVariadic()));

        return match (true) {
            $argument->name instanceof Identifier => $argument->name->toString(),
            array_key_exists($position, $parameters) => $parameters[$position]->getName(),
            $variadic !== [] => $variadic[0]->getName(),
            default => '',
        };
    }
}
