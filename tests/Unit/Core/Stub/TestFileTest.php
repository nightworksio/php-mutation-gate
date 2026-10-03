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
]);

it('writes a new file of one test: Pest\'s closures, or a PHPUnit class named for the file', function () use ($test, $method): void {
    expect(TestFile::created(Path::of('tests/Unit/CartTest.php'), $test, AssertionStyle::Pest))
        ->toBe(sprintf("<?php\n\ndeclare(strict_types=1);\n\n%s\n", $test))
        ->and(TestFile::created(Path::of('tests/Unit/CartTest.php'), $method, AssertionStyle::PhpUnit))->toBe(<<<'CLASS'
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
