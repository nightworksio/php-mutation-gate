<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Stub\TestFile;

$test = "it('kills', function (): void {\n    expect(true)->toBeFalse();\n});";

$method = "public function testKills(): void\n{\n\n    \$this->fail();\n}";

it('reads a file of Pest closures, and adds a test at its end', function () use ($test): void {
    $file = TestFile::read(Path::of('tests/CartTest.php'), Contents::of("<?php\n\nit('fits', fn () => expect(Cart::class)->toBeString());\n\n\n"));

    expect($file->style())->toBe(AssertionStyle::Pest)
        ->and($file->path())->toEqual(Path::of('tests/CartTest.php'))
        ->and($file->with($test))->toBe(sprintf("<?php\n\nit('fits', fn () => expect(Cart::class)->toBeString());\n\n%s\n", $test));
});

it('reads a PHPUnit class, and adds a method before its closing brace, past every brace inside it', function () use ($method): void {
    $class = <<<'CLASS'
    <?php

    namespace Tests;

    use PHPUnit\Framework\TestCase;

    final class CartTest extends TestCase
    {
        public function testFits(): void
        {
            $made = new class {
            };
            $total = match (true) { default => 1 };
        }
    }

    CLASS;
    $file = TestFile::read(Path::of('tests/CartTest.php'), Contents::of($class));

    expect($file->style())->toBe(AssertionStyle::PhpUnit)
        ->and($file->with($method))->toBe(<<<'CLASS'
        <?php

        namespace Tests;

        use PHPUnit\Framework\TestCase;

        final class CartTest extends TestCase
        {
            public function testFits(): void
            {
                $made = new class {
                };
                $total = match (true) { default => 1 };
            }

            public function testKills(): void
            {

                $this->fail();
            }
        }

        CLASS);
});

it('reads a file whose only class is anonymous, or never closes, as Pest closures', function (string $text): void {
    expect(TestFile::read(Path::of('tests/CartTest.php'), Contents::of($text))->style())->toBe(AssertionStyle::Pest);
})->with([
    'anonymous' => ["<?php\n\n\$fake = new class {};\n"],
    'unclosed' => ["<?php\n\nfinal class CartTest\n{\n"],
    'with no body' => ["<?php\n\nfinal class CartTest\n"],
]);

it('writes a new file of one test: Pest\'s closures, or a PHPUnit class named for the file', function () use ($test, $method): void {
    expect(TestFile::created(Path::of('tests/Unit/CartTest.php'), $test, AssertionStyle::Pest, 'Shop'))
        ->toBe(sprintf("<?php\n\ndeclare(strict_types=1);\n\n%s\n", $test))
        ->and(TestFile::created(Path::of('tests/Unit/CartTest.php'), $method, AssertionStyle::PhpUnit, 'Shop'))->toBe(<<<'CLASS'
        <?php

        declare(strict_types=1);

        use PHPUnit\Framework\TestCase;

        final class CartTest extends TestCase
        {
            public function testKills(): void
            {

                $this->fail();
            }
        }

        CLASS);
});

it('reads a spec, by the ObjectBehavior it extends however it is named, as PhpSpec\'s, and adds an example before its closing brace', function (string $parent): void {
    $file = TestFile::read(Path::of('spec/CartSpec.php'), Contents::of(sprintf("<?php\n\nnamespace spec\\Shop;\n\nfinal class CartSpec extends %s\n{\n}\n", $parent)));

    expect($file->style())->toBe(AssertionStyle::PhpSpec)
        ->and($file->with("public function it_kills(): void\n{\n}"))
        ->toBe(sprintf("<?php\n\nnamespace spec\\Shop;\n\nfinal class CartSpec extends %s\n{\n\n    public function it_kills(): void\n    {\n    }\n}\n", $parent));
})->with(['ObjectBehavior', '\\PhpSpec\\ObjectBehavior', 'PhpSpec\\ObjectBehavior']);

it('reads a class that extends another, or nothing, as PHPUnit\'s', function (string $class): void {
    expect(TestFile::read(Path::of('tests/CartTest.php'), Contents::of(sprintf("<?php\n\n%s\n{\n}\n", $class)))->style())->toBe(AssertionStyle::PhpUnit);
})->with([
    'TestCase' => ['final class CartTest extends TestCase'],
    'one named like a spec\'s parent' => ['final class CartTest extends MyObjectBehavior'],
    'nothing' => ['final class CartTest'],
    'an interface, extends after it' => ['final class CartTest implements ObjectBehavior'],
    'one of its own namespace' => ['final class CartTest extends namespace\\ObjectBehavior'],
]);

it('writes a new spec named for the file, in PhpSpec\'s namespace for the class it describes, or its own at the top', function (string $namespace, string $declared): void {
    expect(TestFile::created(Path::of('spec/Shop/CartSpec.php'), "public function it_kills(): void\n{\n}", AssertionStyle::PhpSpec, $namespace))
        ->toBe(sprintf(<<<'SPEC'
        <?php

        declare(strict_types=1);

        namespace %s;

        use PhpSpec\ObjectBehavior;

        final class CartSpec extends ObjectBehavior
        {
            public function it_kills(): void
            {
            }
        }

        SPEC, $declared));
})->with([
    'in a namespace' => ['Shop\\Cart', 'spec\\Shop\\Cart'],
    'at the top' => ['', 'spec'],
]);

it('adds a method before the closing brace of the class, not of a function after it', function () use ($method): void {
    $file = TestFile::read(Path::of('tests/CartTest.php'), Contents::of("<?php\n\nfinal class CartTest\n{\n}\n\nfunction cart(): void\n{\n}\n"));

    expect($file->with($method))->toBe(<<<'CLASS'
        <?php

        final class CartTest
        {

            public function testKills(): void
            {

                $this->fail();
            }
        }

        function cart(): void
        {
        }

        CLASS);
});
