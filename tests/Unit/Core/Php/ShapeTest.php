<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\ClassLike;
use NightWorksIO\MutationGate\Core\Php\Signature;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('finds every class-like body, but not the class a ::class names', function (): void {
    $source = Php::source('<?php interface I { } enum E { } trait T { } class C { const N = C::class; }');
    $shape = $source->shape();
    $bodies = array_values(array_filter(
        range(0, $source->tokens()->count() - 1),
        $shape->isClassBody(...),
    ));

    expect(array_map(static fn(ClassLike $class): string => $class->name(), $shape->classes()))->toBe(['I', 'E', 'T', 'C'])
        ->and($bodies)->toBe([Php::indexOf($source, '{'), Php::indexOf($source, '{', 1), Php::indexOf($source, '{', 2), Php::indexOf($source, '{', 3)])
        ->and($shape->classOf(Php::indexOf($source, '{', 3))->name())->toBe('C');
});

it('finds each function\'s parameter list and body, and no signature in a function import', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        use function Other\helper;
        function &tax($rate = 1) { return $rate; }
        abstract class C {
            abstract public function a($b = 2);
            public function c() { $f = function ($d = 3) use ($rate) { }; $g = fn ($e = 4) => $e; }
        }
        PHP);
    $shape = $source->shape();
    $lists = array_values(array_filter(range(0, $source->tokens()->count() - 1), $shape->isSignature(...)));
    $bodies = array_values(array_filter(range(0, $source->tokens()->count() - 1), $shape->isBody(...)));

    expect(array_map(static fn(int $at): string => $source->tokens()->text($at + 1), $lists))
        ->toBe(['$rate', '$b', ')', '$d', '$e'])
        ->and($shape->signatureOf($lists[0]))->toEqual(Signature::ofFunction('App\tax'))
        ->and($shape->signatureOf($lists[1]))->toEqual(Signature::ofMethod())
        ->and($shape->signatureOf($lists[3]))->toEqual(Signature::ofClosure())
        ->and($shape->signatureOf($lists[4]))->toEqual(Signature::ofClosure())
        ->and(array_map(static fn(int $at): int => $source->tokens()->line($at), $bodies))->toBe([4, 7, 7]);
});

it('finds a brace inside a bracket, and no body where a statement ends first', function (): void {
    $source = Php::source('<?php f(function () { }); interface I { public function g(); }');
    $shape = $source->shape();
    $bodies = array_values(array_filter(range(0, $source->tokens()->count() - 1), $shape->isBody(...)));

    expect($bodies)->toBe([Php::indexOf($source, '{')]);
});
