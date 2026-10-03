<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Hierarchy;
use NightWorksIO\MutationGate\Core\Php\References;
use NightWorksIO\MutationGate\Core\Php\Reflections;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

const REFLECTIONS_CLASSES = <<<'PHP'
    <?php
    namespace App;
    class Base { const RATE = 1; }
    class Money extends Base {}
    class Priced extends Base { const RATE = 2; }
    class Other { const RATE = 3; }
    PHP;

/** Where a file builds a reflection that can read Base::RATE. */
function reflectionsIn(string $code, string $file = 'src/Reader.php'): References
{
    $source = Php::source($code, $file, test: str_starts_with($file, 'tests/'));

    return Reflections::of(Symbol::constant('App\Base', 'RATE'), $source, Hierarchy::of(Php::source(REFLECTIONS_CLASSES), $source));
}

it('reads a constant where a reflection is built on a class that reaches its owner, written out', function (string $reflection): void {
    $read = reflectionsIn(sprintf(<<<'PHP'
        <?php
        namespace App;
        use App\Money as M;
        new \%1$s(Base::class);
        new \%1$s(M::class);
        new \%1$s('App\Base');
        new \%1$s("\\App\\Money");
        new \%1$s(Other::class);
        new \%1$s(Priced::class);
        new \%1$s('App\Other');
        PHP, $reflection));

    expect(Php::sites($read))->toBe(['src/Reader.php:4', 'src/Reader.php:5', 'src/Reader.php:6', 'src/Reader.php:7'])
        ->and($read->isAmbiguous())->toBeFalse();
})->with(['ReflectionClass', 'ReflectionEnum']);

it('reads a constant through self::class and parent::class inside a class whose self or parent reaches its owner', function (): void {
    $read = reflectionsIn(<<<'PHP'
        <?php
        namespace App;
        class Reader extends Base {
            public function own() { return new \ReflectionClass(self::class); }
            public function parent() { return new \ReflectionClass(parent::class); }
        }
        class Apart extends Other {
            public function own() { return new \ReflectionClass(self::class); }
            public function parent() { return new \ReflectionClass(parent::class); }
        }
        PHP);

    expect(Php::sites($read))->toBe(['src/Reader.php:4', 'src/Reader.php:5']);
});

it('reads a constant through a reflection of one constant only where it is built with that name, or one not written out', function (): void {
    $read = reflectionsIn(<<<'PHP'
        <?php
        namespace App;
        new \ReflectionClassConstant(Base::class, 'RATE');
        new \ReflectionClassConstant(Base::class, $name);
        new \ReflectionClassConstant(Base::class, 'OTHER');
        new \ReflectionClassConstant(Other::class, 'RATE');
        PHP);

    expect(Php::sites($read))->toBe(['src/Reader.php:3', 'src/Reader.php:4']);
});

it('reads a constant through a reflection of a class it cannot name only where the file names the owner', function (string $argument): void {
    $naming = reflectionsIn(sprintf("<?php\nnamespace App;\nBase::class;\nnew \\ReflectionClass(%s);\nnew \\ReflectionObject(%s);\n", $argument, $argument));
    $notNaming = reflectionsIn(sprintf("<?php\nnamespace App;\nnew \\ReflectionClass(%s);\nnew \\ReflectionObject(%s);\n", $argument, $argument));

    expect(Php::sites($naming))->toBe(['src/Reader.php:4', 'src/Reader.php:5'])
        ->and(Php::sites($notNaming))->toBe([])
        ->and($naming->isAmbiguous())->toBeFalse();
})->with(['$class', '$object', 'static::class', '$this->owner()', "'App\\\\' . \$name"]);

it('reads a constant through a reflection a test file builds, which judges it itself', function (): void {
    $read = reflectionsIn("<?php\nnamespace App;\nit('reflects', fn () => new \\ReflectionClass(Base::class));\n", 'tests/ReaderTest.php');

    expect(Php::sites($read))->toBe(['tests/ReaderTest.php:3 (test)']);
});

it('reads nothing where a reflection class is named but not built, or something else is built', function (): void {
    $read = reflectionsIn(<<<'PHP'
        <?php
        namespace App;
        use ReflectionClass;
        function described(ReflectionClass $class): string { return $class->getName(); }
        ReflectionClass::class;
        new Base();
        PHP);

    expect(Php::sites($read))->toBe([]);
});
