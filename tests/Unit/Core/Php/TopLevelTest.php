<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\TopLevel;

$top = static fn(string $code): TopLevel => TopLevel::of(array_values(array_filter(
    PhpToken::tokenize($code),
    static fn(PhpToken $token): bool => ! $token->isIgnorable() && ! $token->is(T_CLOSE_TAG),
)));

it('only declares when every statement at the top is a declaration or an import', function () use ($top): void {
    $code = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Domain;

        use App\Clock;
        use Lib\{Timer, Stop as Halt};
        use function App\format;

        const LIMIT = 10;

        #[Attribute, Other([1, 2])]
        final readonly class Money
        {
            public function label(): string
            {
                return "{$this->amount} ${currency}";
            }
        }

        abstract class Base {}

        interface Priced {}

        trait Counted {}

        enum Currency: string
        {
            case Euro = 'EUR';
        }

        function format(): string
        {
            return '';
        }
        PHP;

    expect($top($code)->onlyDeclares())->toBeTrue();
});

it('runs code when any statement at the top does', function (string $code) use ($top): void {
    expect($top($code)->onlyDeclares())->toBeFalse();
})->with([
    'a test' => "<?php\n\nuse App\\Money;\n\nit('adds', function () {\n    expect(1)->toBe(1);\n});\n",
    'a returned value' => "<?php\n\nreturn ['money' => 1];\n",
    'code after a class' => "<?php\n\nclass Money {}\n\nMoney::boot();\n",
    'code with no end' => "<?php\n\nclass Money {}\n\nboot()",
    'text around the code' => "Hello\n<?php\n\nclass Money {}\n",
    'text after the code' => "<?php\n\nclass Money {}\n?>\nHello\n",
    'nothing but a modifier' => "<?php\n\nfinal",
    'an array' => "<?php\n\n[1, 2];\n",
]);

it('only declares when the file ends by closing its tag', function () use ($top): void {
    expect($top("<?php\n\nclass Money {}\n?>\n")->onlyDeclares())->toBeTrue();
});

it('names each class, interface, trait, enum and function declared at the top, as written', function () use ($top): void {
    $code = <<<'PHP'
        <?php

        namespace App;

        use function App\imported;

        const LIMIT = 1;

        #[Attribute]
        final class Money extends Base implements Priced {}

        interface Priced {}

        trait Counted {}

        enum Currency: string {}

        function &format(): string {}

        it('adds', function () {
            $class = new class {};
        });
        PHP;

    expect($top($code)->declared())->toBe(['Money', 'Priced', 'Counted', 'Currency', 'format']);
});

it('declares nothing where nothing is declared', function () use ($top): void {
    expect($top("<?php\n\nreturn 1;\n")->declared())->toBe([]);
});

it('reads the namespace and the imports the statements at the top declare', function () use ($top): void {
    $scope = $top("<?php\n\nnamespace App\\Domain;\n\nuse App\\Clock;\nuse Lib\\{Timer, Stop as Halt};\n")->scope();

    expect($scope->declared('Money'))->toBe('App\Domain\Money')
        ->and($scope->resolve('Clock')->meet(Names::of('App\Clock')))->toBeTrue()
        ->and($scope->resolve('Timer')->meet(Names::of('Lib\Timer')))->toBeTrue()
        ->and($scope->resolve('Halt')->meet(Names::of('Lib\Stop')))->toBeTrue();
});

it('reads a namespace of one segment, and a file with none', function () use ($top): void {
    expect($top("<?php\n\nnamespace App;\n")->scope()->declared('Money'))->toBe('App\Money')
        ->and($top("<?php\n\nclass Money {}\n")->scope()->declared('Money'))->toBe('Money')
        ->and($top("<?php\n\nnamespace {\n}\n")->scope()->declared('Money'))->toBe('Money');
});

it('spells tokens joined by spaces', function (): void {
    expect(TopLevel::spelt(...PhpToken::tokenize('<?php use A\B;')))->toBe('<?php  use   A\B ;');
});
