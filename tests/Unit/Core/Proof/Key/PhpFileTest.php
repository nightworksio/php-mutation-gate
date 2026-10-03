<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Proof\Key\PhpFile;

$read = static fn(string $source): PhpFile => PhpFile::read(Contents::of($source));

it('reads a file that only declares, and every name it declares at its top level', function () use ($read): void {
    $file = $read(<<<'PHP'
        <?php

        /** A docblock. */
        declare(strict_types=1);

        namespace Tests\Support;

        use Foo\Bar, Baz;
        use A\{B, C};

        // A comment.
        # Another.
        #[Attr(1)]
        final class Money extends Model implements Countable
        {
            public const int X = 1;

            public function count(): int
            {
                return strlen(sprintf('%s', [1, 2][0]));
            }

            public function other(): void
            {
            }
        }

        interface Shape {}
        trait Helps {}
        enum Kind {}
        function helper(): void {}
        const LIMIT = [1], OTHER = FOO;
        abstract class Base {}
        readonly class Value {}
        ?>


        PHP);

    expect($file->onlyDeclares())->toBeTrue()
        ->and($file->declares())->toBe(['money', 'shape', 'helps', 'kind', 'helper', 'limit', 'other', 'base', 'value']);
});

it('reads a function or constant a file imports as a name it uses, not one it declares', function () use ($read): void {
    $file = $read(<<<'PHP_WRAP'
    <?php
    
    namespace Tests\Support;
    
    use function count;
    use function sprintf, strlen;
    use const PHP_EOL;
    use const Foo\LIMIT, Foo\OTHER;
    use Foo\{Bar, function baz, const QUX};
    
    final class Money
    {
    }
    
    function helper(): void {}
    const MAX = 1;
    
    PHP_WRAP);

    expect($file->onlyDeclares())->toBeTrue()
        ->and($file->declares())->toBe(['money', 'helper', 'max'])
        ->and($file->names())->toContain('count', 'sprintf', 'php_eol', 'limit', 'baz', 'qux');
});

it('reads a file that runs something when it is loaded, and declares nothing of it', function (string $source) use ($read): void {
    $file = $read($source);

    expect($file->onlyDeclares())->toBeFalse()
        ->and($file->declares())->toBe([]);
})->with([
    'a call after a class' => ["<?php\nclass Money {}\nfoo();\n"],
    'a call after a namespace' => ["<?php\nnamespace A;\nfoo();\n"],
    'a call after an attribute' => ["<?php\n#[Attr]\nfoo();\n"],
    'a call after a grouped import' => ["<?php\nuse A\\{B, C};\nfoo();\n"],
    'a call after a declaration of strict types' => ["<?php\ndeclare(strict_types=1);\nfoo();\n"],
    'a call after a constant list' => ["<?php\nconst A = [1];\nfoo();\n"],
    'an assignment' => ["<?php\n\$x = 1;\n"],
    'a namespace with braces, whose inside is not read' => ["<?php\nnamespace A {\n    class B {}\n}\n"],
    'a declaration with braces' => ["<?php\ndeclare(ticks=1) {\n}\n"],
    'text before the code' => ["hello <?php class A {}\n"],
]);

it('reads no statement at all as only declaring', function () use ($read): void {
    expect($read('')->onlyDeclares())->toBeTrue()
        ->and($read('')->declares())->toBe([]);
});

it('reads every name a file mentions, by its last segment, ignoring case', function () use ($read): void {
    $file = $read("<?php\nuse Foo\\Bar;\n\\Baz\\Qux::Run(namespace\\Rel::x());\n");

    expect($file->names())->toBe(['bar', 'qux', 'run', 'rel', 'x']);
});

it('reads every word of its text, a hyphenated word also joined up', function () use ($read): void {
    $file = $read("Hello World <?php echo 'Ready-Steady go 7', \"X-ray \$z\";");

    expect($file->names())->toBe(['hello', 'world', 'readysteady', 'ready', 'steady', 'go', '7', 'xray', 'x', 'ray']);
});
