<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\ClassLike;
use NightWorksIO\MutationGate\Core\Php\Site;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('is a test or a source file at a path, read by its significant tokens', function (): void {
    $source = Php::source("<?php\n// a comment\nconst A = 1; ?>\n", 'tests/ATest.php', test: true);

    expect($source->path())->toEqual(Path::of('tests/ATest.php'))
        ->and($source->isTest())->toBeTrue()
        ->and(Php::source('<?php')->isTest())->toBeFalse()
        ->and(Source::ofCode(Path::of('src/A.php'), Contents::of('<?php'))->isTest())->toBeFalse()
        ->and($source->tokens()->count())->toBe(5)
        ->and($source->lineOf(Php::indexOf($source, 'A')))->toEqual(Line::of(3));
});

it('reads a file of the code the tests judge as no test, and a test file as one', function (): void {
    $read = [Source::ofCode(Path::of('src/A.php'), Contents::of('<?php')), Source::ofTest(Path::of('tests/ATest.php'), Contents::of('<?php'))];

    expect(array_map(static fn(Source $source): array => [$source->path()->value(), $source->isTest()], $read))
        ->toBe([['src/A.php', false], ['tests/ATest.php', true]]);
});

it('reads the scope a file\'s code ends in from its contents alone', function (): void {
    $scope = Source::scopeOf(Contents::of("<?php\n// a comment\nnamespace Shop\\Cart;\n\nfinal class Line {}\n"));

    expect($scope->namespace())->toBe('Shop\\Cart')
        ->and($scope->declared('Line'))->toBe('Shop\\Cart\\Line')
        ->and(Source::scopeOf(Contents::of('<?php'))->namespace())->toBe('');
});

it('says what a token stands in, and the class around it', function (): void {
    $source = Php::source('<?php namespace App; class C { const A = 1; public function f() { return 2; } } $x = 3;');

    expect($source->symbolAt(Php::indexOf($source, '1')))->toEqual(Symbol::constant('App\C', 'A'))
        ->and($source->classAround(Php::indexOf($source, '2'))->name())->toBe('App\C')
        ->and($source->classAround(Php::indexOf($source, '3')))->toEqual(ClassLike::none());
});

it('finds the first token a change to a print of the file writes differently, carried back to the file', function (): void {
    $source = Php::source('<?php f(1, 2,); $a = 0o17 + 2; g(3,);');
    $print = Contents::of("<?php\n\nf(1, 2);\n\$a = 017 + 2;\ng(3);\n");

    expect($source->changedAt($print, Contents::of('<?php f(1, 2); $a = 017 - 2; g(3);')))->toBe(Php::indexOf($source, '+'))
        ->and($source->changedAt($print, Contents::of('<?php f(1, 2); $a = 016 + 2; g(3);')))->toBe(Php::indexOf($source, '0o17'))
        ->and($source->changedAt($print, Contents::of('<?php f(1, 2); $a = 017;')))->toBe(Php::indexOf($source, '+'))
        ->and($source->changedAt($print, $print))->toBe($source->tokens()->count())
        ->and($source->changedAt($print, Contents::of('<?php f(1, 2); $a = 017 + 2;')))->toBe(Php::indexOf($source, 'g'));
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

it('finds no use of a class where its name is declared as a class-like, a function or a constant, or read through ?->', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        class Status {}
        interface Status {}
        trait Status {}
        function Status() {}
        const Status = 1;
        $x?->Status;
        new Status();
        PHP);

    expect(array_map($source->tokens()->line(...), $source->namesOf('App\Status')))->toBe([9]);
});

it('finds the body that runs a read in a parameter default, and none for a token in no default', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        class C {
            public function f(
                $a = self::A,
            ) {
                return $a;
            }
            abstract public function g($b = self::B);
            public function h() { return self::C; }
        }
        f(C::D);
        PHP, 'src/C.php');
    $run = $source->defaultRunBy(Php::indexOf($source, 'A'));

    expect($run instanceof Site ? [$run->line(), $run->last()] : $run)->toEqual([Line::of(5), Line::of(7)])
        ->and($source->defaultRunBy(Php::indexOf($source, 'B')))->toBeInstanceOf(NotGiven::class)
        ->and($source->defaultRunBy(Php::indexOf($source, 'C', 1)))->toBeInstanceOf(NotGiven::class)
        ->and($source->defaultRunBy(Php::indexOf($source, 'D')))->toBeInstanceOf(NotGiven::class);
});
