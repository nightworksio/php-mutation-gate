<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Mutator\Engine\Edit;
use NightWorksIO\MutationGate\Tests\Support\ReadCode;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeVisitor;

it('prints a replacement alone in the place of the node it replaces', function (): void {
    $code = "<?php\n\$a = 1 + 2;\n";

    expect(ReadCode::of($code)->edit(Int_::class, 0, new Int_(5)))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $code, 11, 1, '5'));
});

it('prints a replacement with the parentheses its parent\'s operator asks of it', function (): void {
    $code = "<?php\n\$a * \$b;\n";

    expect(ReadCode::of($code)->edit(Variable::class, 1, new Plus(new Variable('c'), new Variable('d'))))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $code, 11, 2, '($c + $d)'));
});

it('prints a replacement in a list with what the list asks of its items', function (): void {
    $code = "<?php\n\"\$a\$b\";\n";

    expect(ReadCode::of($code)->edit(Variable::class, 0, new Variable('x')))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $code, 7, 2, '{$x}'));
});

it('prints a statement alone in its list where it keeps the comments the list would print', function (): void {
    $code = "<?php\nfunction f()\n{\n    \$a = 1;\n}\n";

    expect(ReadCode::of($code)->edit(Expression::class, 0, new Expression(new Assign(new Variable('a'), new Int_(2)))))
        ->toEqual(Edit::spliced(Line::of(4), Line::of(4), $code, 25, 7, '$a = 2;'));
});

it('prints the parent of a statement that drops its comments, as the list prints them afresh', function (): void {
    $code = "<?php\nfunction f()\n{\n    // note\n    \$a = 1;\n}\n";

    expect(ReadCode::of($code)->edit(Expression::class, 0, new Expression(new Assign(new Variable('a'), new Int_(2)))))
        ->toEqual(Edit::spliced(Line::of(5), Line::of(5), $code, 6, 40, "function f()\n{\n    \$a = 2;\n}"));
});

it('prints the parent of a removed statement in the parent\'s place, at the parent\'s indentation', function (): void {
    $code = "<?php\nclass A\n{\n    public function f()\n    {\n        \$a = 1;\n        \$b = 2;\n    }\n}\n";

    expect(ReadCode::of($code)->edit(Expression::class, 0, NodeVisitor::REMOVE_NODE))->toEqual(Edit::spliced(
        Line::of(6),
        Line::of(6),
        $code,
        20,
        63,
        "public function f()\n    {\n        \$b = 2;\n    }",
    ));
});

it('prints the parent of a replacement that prints nothing', function (): void {
    $code = "<?php\nfunction f()\n{\n    \$a = 1;\n}\n";

    expect(ReadCode::of($code)->edit(Expression::class, 0, new Nop()))
        ->toEqual(Edit::spliced(Line::of(4), Line::of(4), $code, 6, 28, "function f()\n{\n    \n}"));
});

it('prints a replacement alone at the end of the code', function (): void {
    $code = "<?php\nnamespace A;\necho 1;";

    expect(ReadCode::of($code)->edit(Echo_::class, 0, new Echo_([new Int_(2)])))
        ->toEqual(Edit::spliced(Line::of(3), Line::of(3), $code, 19, 7, 'echo 2;'));
});

it('prints a namespace emptied of its statement afresh, as the code declares it', function (): void {
    $code = "<?php\nnamespace A;\necho 1;\n";

    expect(ReadCode::of($code)->edit(Echo_::class, 0, NodeVisitor::REMOVE_NODE))
        ->toEqual(Edit::spliced(Line::of(3), Line::of(3), $code, 6, 20, "namespace A;\n"));
});

it('prints the whole code where a statement at the top of it is removed', function (): void {
    $code = "<?php\n\$a = 1;\n\$b = 2;\n";

    expect(ReadCode::of($code)->edit(Expression::class, 0, NodeVisitor::REMOVE_NODE))
        ->toEqual(Edit::of(Line::of(2), Line::of(2), $code, "<?php\n\$b = 2;\n"));
});

it('prints the whole code where neither a replacement nor its parent could stand alone without joining the word before', function (): void {
    $code = "<?php\nreturn-1+2;\n";

    expect(ReadCode::of($code)->edit(UnaryMinus::class, 0, new ConstFetch(new Name('a'))))
        ->toEqual(Edit::of(Line::of(2), Line::of(2), $code, "<?php\nreturn a+2;\n"));
});

it('prints a replacement alone in a subnode of its own, after a list of its parent\'s', function (): void {
    $code = "<?php\nfunction f(\$a): /* c */ int\n{\n}\n";

    expect(ReadCode::of($code)->edit(Identifier::class, 1, new Identifier('string')))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $code, 30, 3, 'string'));
});

it('prints a replacement alone after a word where it starts with no word of its own', function (): void {
    $code = "<?php\ninclude\"f\";\n";

    expect(ReadCode::of($code)->edit(String_::class, 0, new String_('g')))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $code, 13, 3, "'g'"));
});

it('prints a replacement alone before a word where it ends with no word of its own', function (): void {
    $code = "<?php\n`ls \$a[0]b`;\n";

    expect(ReadCode::of($code)->edit(ArrayDimFetch::class, 0, new ArrayDimFetch(new Variable('x'), new Int_(1))))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $code, 10, 5, '$x[1]'));
});

it('prints the parent of a replacement that would join the word before it, parted by a space', function (): void {
    $code = "<?php\ninclude\"f\";\n";

    expect(ReadCode::of($code)->edit(String_::class, 0, new ConstFetch(new Name('X'))))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $code, 6, 10, 'include X'));
});

it('prints the parent of a replacement that would join the word after it, parted by a space', function (): void {
    $code = "<?php\n`ls \$a[0]b`;\n";

    expect(ReadCode::of($code)->edit(ArrayDimFetch::class, 0, new Variable('x')))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $code, 6, 11, '`ls $x b`'));
});

it('prints a class name alone in a new, and the new of an anonymous class whole', function (): void {
    $named = "<?php\nnew Foo;\n";
    $anonymous = "<?php\nnew class {};\n";

    expect(ReadCode::of($named)->edit(Name::class, 0, new Name('Bar')))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $named, 10, 3, 'Bar'))
        ->and(ReadCode::of($anonymous)->edit(Class_::class, 0, new Class_(null, ['stmts' => [new Nop()]])))
        ->toEqual(Edit::spliced(Line::of(2), Line::of(2), $anonymous, 6, 12, "new class\n{\n    \n}"));
});
