<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\PcovLines;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** The function a method's one statement calls with no argument, or what it does instead. */
function pcovCallOf(ClassMethod $method): string
{
    $statements = $method->stmts ?? [];
    $statement = count($statements) === 1 ? $statements[0] : null;
    $call = $statement instanceof Expression || $statement instanceof Return_ ? $statement->expr : null;

    return $call instanceof FuncCall && $call->name instanceof Name && $call->args === []
        ? $call->name->toString()
        : sprintf('%d statements', count($statements));
}

it('does nothing but call pcov, each method the function of pcov it is named for', function (): void {
    $file = new ReflectionClass(PcovLines::class)->getFileName();
    $parsed = new ParserFactory()->createForHostVersion()->parse((string) file_get_contents((string) $file)) ?? [];
    $nodes = new NodeTraverser(new NameResolver())->traverse($parsed);
    $finder = new NodeFinder();
    $calls = [];

    foreach ($finder->findInstanceOf($nodes, ClassMethod::class) as $method) {
        $calls[$method->name->toString()] = pcovCallOf($method);
    }

    expect($calls)->toBe([
        'clear' => 'pcov\clear',
        'start' => 'pcov\start',
        'stop' => 'pcov\stop',
        'collect' => 'pcov\collect',
    ])
        ->and($finder->findInstanceOf($nodes, Property::class))->toBe([]);
});
