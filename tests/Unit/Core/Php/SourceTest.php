<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\ClassLike;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('is a test or a source file at a path, read by its significant tokens', function (): void {
    $source = Php::source("<?php\n// a comment\nconst A = 1; ?>\n", 'tests/ATest.php', test: true);

    expect($source->path())->toEqual(Path::of('tests/ATest.php'))
        ->and($source->isTest())->toBeTrue()
        ->and(Php::source('<?php')->isTest())->toBeFalse()
        ->and($source->tokens()->count())->toBe(5)
        ->and($source->lineOf(Php::indexOf($source, 'A')))->toEqual(Line::of(3));
});

it('says what a token stands in, and the class around it', function (): void {
    $source = Php::source('<?php namespace App; class C { const A = 1; public function f() { return 2; } } $x = 3;');

    expect($source->symbolAt(Php::indexOf($source, '1')))->toEqual(Symbol::constant('App\C', 'A'))
        ->and($source->classAround(Php::indexOf($source, '2'))->name())->toBe('App\C')
        ->and($source->classAround(Php::indexOf($source, '3')))->toEqual(ClassLike::none());
});

it('finds the first token a changed copy writes differently, or none', function (): void {
    $source = Php::source('<?php $a = 1 + 2;');

    expect($source->changedAt(Contents::of('<?php $a = 1 - 2;')))->toBe(Php::indexOf($source, '+'))
        ->and($source->changedAt(Contents::of("<?php\n\n\$a  =  1 + 2;")))->toBe($source->tokens()->count())
        ->and($source->changedAt(Contents::of('<?php $a = 1;')))->toBe(Php::indexOf($source, '+'));
});

it('finds each name that may stand for a class, but not where it is declared, imported or a member', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        use Other\Status as S;
        enum Status { case Status; }
        function f(S $s, Status $t): S { return S::from(1)->Status ?? Status::Status; }
        PHP);
    $lines = [];

    foreach ($source->namesOf('app\status') as $at) {
        $lines[] = $source->tokens()->line($at);
    }

    expect(count($source->namesOf('Other\Status')))->toBe(3)
        ->and(count($source->namesOf('App\Status')))->toBe(2)
        ->and($lines)->toBe([5, 5])
        ->and($source->namesOf('Nowhere'))->toBe([]);
});
