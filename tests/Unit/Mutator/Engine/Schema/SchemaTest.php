<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Engine\Schema\Site;
use NightWorksIO\MutationGate\Tests\Support\Nodes;
use NightWorksIO\MutationGate\Tests\Support\Schemata;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Div;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitor;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A value as var_export writes it.
 *
 * @param int|float|array<int|string, int|float|string|array<int|string, int|string>> $value
 */
function exported(int|float|array $value): string
{
    return var_export($value, return: true);
}

/** A Plus turned into a Minus, as a mutator makes it: a new node over the original's operands. */
function minusOf(Plus $plus): Minus
{
    return new Minus($plus->left, $plus->right);
}

it('switches an expression by a ternary on its own line: the original wherever its id is off', function (): void {
    $code = "<?php\n\nreturn 1 + 2;\n";
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)]);

    expect(Schemata::returned($schema, 0))->toBe(exported(3))
        ->and(Schemata::returned($schema, 1))->toBe(exported(-1))
        ->and(Schemata::returned($schema, 2))->toBe(exported(3))
        ->and($schema->text())->toBe(sprintf("<?php\n\nreturn (%s ? (1 - 2) : (1 + 2));\n", sprintf(Schemata::switch(), 1)))
        ->and([$schema->switches(1), $schema->switches(2), $schema->isEmpty()])->toBe([true, false, false]);
});

it('switches a site within another inside the other\'s original, and a site of the same node inside the other', function (): void {
    $code = "<?php\n\nreturn 2 * (3 + 4);\n";
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [
        Site::of($nodes->nth(Mul::class), new Div($nodes->nth(Mul::class)->left, $nodes->nth(Mul::class)->right), 1),
        Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 2),
        Site::of($nodes->nth(Plus::class), new Mul($nodes->nth(Plus::class)->left, $nodes->nth(Plus::class)->right), 3),
    ]);

    expect(array_map(static fn(int $on): string => Schemata::returned($schema, $on), [0, 1, 2, 3]))
        ->toBe(array_map(exported(...), [14, 2 / 7, -2, 24]));
});

it('switches a statement by an if, a removal leaving the original\'s place empty', function (): void {
    $code = "<?php\n\n\$items = new ArrayObject();\n\$items->append(1);\n\nreturn count(\$items);\n";
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [Site::of($nodes->nth(Expression::class, 1), new Nop(), 1)]);

    expect([Schemata::returned($schema, 0), Schemata::returned($schema, 1)])->toBe([exported(1), exported(0)]);
});

it('switches an item of an array by a spread, keeping the keys and order of the others', function (): void {
    $code = "<?php\n\nreturn [['a', 'b', 'c'], ['x' => 1, 'y' => 2], [5 => 'f', 'g']];\n";
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [
        Site::of($nodes->nth(ArrayItem::class, 2), NodeVisitor::REMOVE_NODE, 1),
        Site::of($nodes->nth(ArrayItem::class, 5), NodeVisitor::REMOVE_NODE, 2),
        Site::of($nodes->nth(ArrayItem::class, 9), NodeVisitor::REMOVE_NODE, 3),
    ]);

    expect(Schemata::returned($schema, 0))->toBe(exported([['a', 'b', 'c'], ['x' => 1, 'y' => 2], [5 => 'f', 6 => 'g']]))
        ->and(Schemata::returned($schema, 1))->toBe(exported([['a', 'c'], ['x' => 1, 'y' => 2], [5 => 'f', 6 => 'g']]))
        ->and(Schemata::returned($schema, 2))->toBe(exported([['a', 'b', 'c'], ['y' => 2], [5 => 'f', 6 => 'g']]))
        ->and(Schemata::returned($schema, 3))->toBe(exported([['a', 'b', 'c'], ['x' => 1, 'y' => 2], [5 => 'f']]));
});

it('keeps every line where it stood, the mutant printed on one line and its comments left out', function (): void {
    $code = "<?php\n\nreturn [\n    1\n        + 2,\n    __LINE__,\n];\n";
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)]);

    expect(Schemata::returned($schema, 0))->toBe(exported([3, 6]))
        ->and(Schemata::returned($schema, 1))->toBe(exported([-1, 6]))
        ->and(substr_count($schema->text(), "\n"))->toBe(substr_count($code, "\n"));
});

/** The site a dataset's closure names among a test's nodes. */
function siteBy(Closure $site, Nodes $nodes): Site
{
    $named = $site($nodes);

    return $named instanceof Site ? $named : throw new LogicException('A dataset names no site.');
}

it('switches no site where a ternary, an if or a spread could not stand, and keeps the file as it was where it switches none', function (string $code, Closure $site): void {
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [siteBy($site, $nodes)]);

    expect([$schema->isEmpty(), $schema->switches(1), $schema->text()])->toBe([true, false, $code]);
})->with([
    'a constant expression' => ["<?php\n\nconst SUM = 1 + 2;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'a default' => ["<?php\n\nfunction add(\$a = 1 + 2) { return \$a; }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'a string\'s interpolation' => ["<?php\n\nreturn \"{\$a->b()}\";\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(MethodCall::class), new ConstFetch(new Name('null')), 1)],
    'a call passed as an argument a reference may take' => ["<?php\n\n\$x->sort(\$y->all());\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(MethodCall::class, 1), new ConstFetch(new Name('null')), 1)],
    'an item with a key of an integer' => ["<?php\n\nreturn [5 => 'f'];\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(ArrayItem::class), NodeVisitor::REMOVE_NODE, 1)],
    'an item with a key of text an integer reads as' => ["<?php\n\nreturn ['5' => 'f'];\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(ArrayItem::class), NodeVisitor::REMOVE_NODE, 1)],
    'a statement declaring a function' => ["<?php\n\nfunction f() {}\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Function_::class), new Nop(), 1)],
    'a class\'s method' => ["<?php\n\nclass A { public function f() {} }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(ClassMethod::class), new Nop(), 1)],
    'an expression replaced by a statement' => ["<?php\n\nreturn 1 + 2;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), new Nop(), 1)],
    'a replacement that spans lines' => ["<?php\n\nreturn 'a';\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(String_::class), new String_("x\ny", ['kind' => String_::KIND_HEREDOC, 'docLabel' => 'EOT']), 1)],
    'a return replaced by an expression' => ["<?php\n\nreturn 1;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Return_::class), new Int_(2), 1)],
    'a variable' => ["<?php\n\nreturn \$a;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Variable::class), new ConstFetch(new Name('null')), 1)],
    'an index' => ["<?php\n\nreturn \$a[0];\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(ArrayDimFetch::class), new ConstFetch(new Name('null')), 1)],
    'a property' => ["<?php\n\nreturn \$a->b;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(PropertyFetch::class), new ConstFetch(new Name('null')), 1)],
    'a nullsafe property' => ["<?php\n\nreturn \$a?->b;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(NullsafePropertyFetch::class), new ConstFetch(new Name('null')), 1)],
    'a static property' => ["<?php\n\nreturn A::\$b;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(StaticPropertyFetch::class), new ConstFetch(new Name('null')), 1)],
    'a list' => ["<?php\n\n[\$a] = \$b;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(List_::class), new ConstFetch(new Name('null')), 1)],
    'a class constant' => ["<?php\n\nclass A { const B = 1 + 2; }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'a property\'s default' => ["<?php\n\nclass A { public \$a = 1 + 2; }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'a static variable\'s default' => ["<?php\n\nfunction f() { static \$a = 1 + 2; }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an attribute\'s argument' => ["<?php\n\n#[A(1 + 2)]\nfunction f() {}\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'a declare' => ["<?php\n\ndeclare(ticks=1 + 2);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an enum case' => ["<?php\n\nenum E: int { case A = 1 + 2; }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'a shell command\'s interpolation' => ["<?php\n\nreturn `ls {\$a->b()}`;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(MethodCall::class), new ConstFetch(new Name('null')), 1)],
    'an isset' => ["<?php\n\nreturn isset(\$a[1 + 2]);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an unset' => ["<?php\n\nunset(\$a[1 + 2]);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'a global' => ["<?php\n\nfunction f() { global \${'a' . 'b'}; }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Concat::class), new String_('c'), 1)],
    'a function call passed as an argument a reference may take' => ["<?php\n\n\$x->sort(f());\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(FuncCall::class), new ConstFetch(new Name('null')), 1)],
    'a nullsafe call passed as an argument a reference may take' => ["<?php\n\n\$x->sort(\$y?->all());\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(NullsafeMethodCall::class), new ConstFetch(new Name('null')), 1)],
    'a static call passed as an argument a reference may take' => ["<?php\n\n\$x->sort(Y::all());\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(StaticCall::class), new ConstFetch(new Name('null')), 1)],
    'a new object passed as an argument a reference may take' => ["<?php\n\n\$x->sort(new Y());\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(New_::class), new ConstFetch(new Name('null')), 1)],
    'an assignment passed as an argument a reference may take' => ["<?php\n\n\$x->sort(\$y = 1);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Assign::class), new ConstFetch(new Name('null')), 1)],
    'an assignment by an operator passed as an argument a reference may take' => ["<?php\n\n\$x->sort(\$y += 1);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(AssignOp::class), new ConstFetch(new Name('null')), 1)],
    'a pre-increment passed as an argument a reference may take' => ["<?php\n\n\$x->sort(++\$y);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(PreInc::class), new ConstFetch(new Name('null')), 1)],
    'a pre-decrement passed as an argument a reference may take' => ["<?php\n\n\$x->sort(--\$y);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(PreDec::class), new ConstFetch(new Name('null')), 1)],
    'a post-increment passed as an argument a reference may take' => ["<?php\n\n\$x->sort(\$y++);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(PostInc::class), new ConstFetch(new Name('null')), 1)],
    'a post-decrement passed as an argument a reference may take' => ["<?php\n\n\$x->sort(\$y--);\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(PostDec::class), new ConstFetch(new Name('null')), 1)],
    'an index written to' => ["<?php\n\n\$a[1 + 2] = 3;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an index an operator writes to' => ["<?php\n\n\$a[1 + 2] += 3;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an index taken by reference' => ["<?php\n\n\$a = &\$b[1 + 2];\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an index an array item takes by reference' => ["<?php\n\nreturn [&\$b[1 + 2]];\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an index a foreach writes to' => ["<?php\n\nforeach (\$a as \$b[1 + 2]) {}\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an index a foreach takes by reference' => ["<?php\n\nforeach (\$a[1 + 2] as &\$b) {}\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)],
    'an item taken by reference' => ["<?php\n\nreturn [&\$a];\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(ArrayItem::class), NodeVisitor::REMOVE_NODE, 1)],
    'an item of a constant expression\'s array' => ["<?php\n\nconst A = [1, 2];\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(ArrayItem::class), NodeVisitor::REMOVE_NODE, 1)],
    'an if that declares a function' => ["<?php\n\nif (\$a) { function f() {} }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(If_::class), NodeVisitor::REMOVE_NODE, 1)],
    'an if that declares a class' => ["<?php\n\nif (\$a) { class A {} }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(If_::class), NodeVisitor::REMOVE_NODE, 1)],
    'an if that holds a label' => ["<?php\n\nif (\$a) { a: \$b = 1; }\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(If_::class), NodeVisitor::REMOVE_NODE, 1)],
    'a statement replaced by one PHP cannot read inside an if' => ["<?php\n\n\$a = 1;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Expression::class), new Namespace_(new Name('A')), 1)],
    'a statement replaced by one that declares a function' => ["<?php\n\n\$a = 1;\n", static fn(Nodes $nodes): Site => Site::of($nodes->nth(Expression::class), new Function_('f'), 1)],
]);

/** The node a dataset's closure names among a test's nodes. */
function nodeBy(Closure $node, Nodes $nodes): Node
{
    $named = $node($nodes);

    return $named instanceof Node ? $named : throw new LogicException('A dataset names no node.');
}

it('switches each kind of statement an if can stand in place of', function (string $code, Closure $statement): void {
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [Site::of(nodeBy($statement, $nodes), NodeVisitor::REMOVE_NODE, 1)]);

    expect($schema->switches(1))->toBeTrue();
})->with([
    'an expression' => ["<?php\n\n\$a = 1;\n", static fn(Nodes $nodes): Node => $nodes->nth(Expression::class)],
    'a return' => ["<?php\n\nreturn 1;\n", static fn(Nodes $nodes): Node => $nodes->nth(Return_::class)],
    'an echo' => ["<?php\n\necho 1;\n", static fn(Nodes $nodes): Node => $nodes->nth(Echo_::class)],
    'an if' => ["<?php\n\nif (\$a) { \$b = 1; }\n", static fn(Nodes $nodes): Node => $nodes->nth(If_::class)],
    'a while' => ["<?php\n\nwhile (\$a) { \$a--; }\n", static fn(Nodes $nodes): Node => $nodes->nth(While_::class)],
    'a do' => ["<?php\n\ndo { \$a--; } while (\$a);\n", static fn(Nodes $nodes): Node => $nodes->nth(Do_::class)],
    'a for' => ["<?php\n\nfor (;;) { \$a = 1; }\n", static fn(Nodes $nodes): Node => $nodes->nth(For_::class)],
    'a foreach' => ["<?php\n\nforeach (\$a as \$b) {}\n", static fn(Nodes $nodes): Node => $nodes->nth(Foreach_::class)],
    'a switch' => ["<?php\n\nswitch (\$a) { default: }\n", static fn(Nodes $nodes): Node => $nodes->nth(Switch_::class)],
    'a try' => ["<?php\n\ntry { \$a = 1; } catch (Exception) {}\n", static fn(Nodes $nodes): Node => $nodes->nth(TryCatch::class)],
    'an unset' => ["<?php\n\nunset(\$a);\n", static fn(Nodes $nodes): Node => $nodes->nth(Unset_::class)],
    'a comment standing as a statement' => ["<?php\n\n\$a = 1;\n// end\n", static fn(Nodes $nodes): Node => $nodes->nth(Nop::class)],
    'a break' => ["<?php\n\nwhile (true) { break; }\n", static fn(Nodes $nodes): Node => $nodes->nth(Break_::class)],
    'a continue' => ["<?php\n\nforeach (\$a as \$b) { continue; }\n", static fn(Nodes $nodes): Node => $nodes->nth(Continue_::class)],
]);

it('switches the sites that can stand beside one that cannot, which would leave the schema unread as PHP', function (): void {
    $code = "<?php\n\nclass A { public function f() { return 1 + 2; } }\n";
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [
        Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1),
        Site::of($nodes->nth(ClassMethod::class), NodeVisitor::REMOVE_NODE, 2),
    ]);

    expect([$schema->switches(1), $schema->switches(2)])->toBe([true, false]);
});

it('switches an item of an array replaced by another item', function (): void {
    $code = "<?php\n\nreturn ['a', 'b'];\n";
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [Site::of($nodes->nth(ArrayItem::class), new ArrayItem(new String_('z')), 1)]);

    expect([Schemata::returned($schema, 0), Schemata::returned($schema, 1)])->toBe([exported(['a', 'b']), exported(['z', 'b'])]);
});

it('switches an expression a ternary can stand in place of: a value assigned, one in a closure\'s body or a loop\'s, an argument PHP passes by value', function (string $code): void {
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1)]);

    expect($schema->switches(1))->toBeTrue();
})->with([
    'a value assigned' => ["<?php\n\n\$a = 1 + 2;\n"],
    'a value an operator assigns' => ["<?php\n\n\$a += 1 + 2;\n"],
    'the body of a closure in a constant expression' => ["<?php\n\nclass A { const B = static fn() => 1 + 2; }\n"],
    'a statement in the body of a foreach' => ["<?php\n\nforeach (\$a as \$b) { \$c = 1 + 2; }\n"],
    'an argument that is no call, new object or assignment' => ["<?php\n\n\$x->sort(1 + 2);\n"],
]);

it('switches sites that start at one place, the longer around the shorter, whatever order they come in', function (): void {
    $code = "<?php\n\nreturn [1 + 2 - 3, 4 * 5];\n";
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [
        Site::of($nodes->nth(Mul::class), new Div($nodes->nth(Mul::class)->left, $nodes->nth(Mul::class)->right), 3),
        Site::of($nodes->nth(Plus::class), minusOf($nodes->nth(Plus::class)), 1),
        Site::of($nodes->nth(Minus::class), new Plus($nodes->nth(Minus::class)->left, $nodes->nth(Minus::class)->right), 2),
    ]);

    expect(array_map(static fn(int $on): string => Schemata::returned($schema, $on), [0, 1, 2, 3]))
        ->toBe(array_map(exported(...), [[0, 20], [-4, 20], [6, 20], [0, 4 / 5]]));
});

it('prints a mutant whose code carries a comment on one line, the comment left out', function (): void {
    $code = "<?php\n\n\$a = 1;\n\nreturn \$a;\n";
    $replacement = new Expression(new Assign(new Variable('a'), new Int_(2)), ['comments' => [new Comment('// two')]]);
    $schema = Schemata::of($code, static fn(Nodes $nodes): array => [Site::of($nodes->nth(Expression::class), $replacement, 1)]);

    expect([Schemata::returned($schema, 0), Schemata::returned($schema, 1)])->toBe([exported(1), exported(2)])
        ->and($schema->text())->not->toContain('// two')
        ->and(substr_count($schema->text(), "\n"))->toBe(substr_count($code, "\n"));
});
