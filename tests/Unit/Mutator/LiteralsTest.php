<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Literals;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\PrettyPrinter\Standard;

it('writes true, false, null and an empty array as code writes them', function (): void {
    $printer = new Standard();

    expect($printer->prettyPrintExpr(Literals::true()))->toBe('true')
        ->and($printer->prettyPrintExpr(Literals::false()))->toBe('false')
        ->and($printer->prettyPrintExpr(Literals::null()))->toBe('null')
        ->and($printer->prettyPrintExpr(Literals::emptyArray()))->toBe('[]');
});

it('recognises true and false only as written in lower case and unqualified', function (): void {
    expect(Literals::isTrue(new ConstFetch(new Name('true'))))->toBeTrue()
        ->and(Literals::isTrue(new ConstFetch(new Name('TRUE'))))->toBeFalse()
        ->and(Literals::isTrue(new ConstFetch(new FullyQualified('true'))))->toBeFalse()
        ->and(Literals::isTrue(new ConstFetch(new Name('false'))))->toBeFalse()
        ->and(Literals::isFalse(new ConstFetch(new Name('false'))))->toBeTrue()
        ->and(Literals::isFalse(new ConstFetch(new Name('FALSE'))))->toBeFalse()
        ->and(Literals::isFalse(new ConstFetch(new Name('true'))))->toBeFalse();
});

it('recognises null in lower case, and an array without items', function (): void {
    expect(Literals::isNull(new ConstFetch(new Name('null'))))->toBeTrue()
        ->and(Literals::isNull(new ConstFetch(new FullyQualified('null'))))->toBeTrue()
        ->and(Literals::isNull(new ConstFetch(new Name('NULL'))))->toBeFalse()
        ->and(Literals::isNull(new Variable('null')))->toBeFalse()
        ->and(Literals::isEmptyArray(new Array_()))->toBeTrue()
        ->and(Literals::isEmptyArray(new Array_([new ArrayItem(new Variable('a'))])))->toBeFalse()
        ->and(Literals::isEmptyArray(new Variable('a')))->toBeFalse();
});
