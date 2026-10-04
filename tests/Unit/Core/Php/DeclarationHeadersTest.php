<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\DeclarationHeaders;

$same = static fn(string $original, string $mutant): bool => DeclarationHeaders::in(Contents::of($original))
    ->same(DeclarationHeaders::in(Contents::of($mutant)));

$type = <<<'PHP'
    <?php

    namespace App;

    #[Attribute]
    final class Money extends Value implements Stringable
    {
        use Rounds;

        public const int SCALE = 2;

        private int $cents = 0;

        public function __construct(private int $amount)
        {
            $this->cents = $amount * 100;
        }

        public function isPositive(int $floor = 0): bool
        {
            return $this->amount > $floor;
        }
    }

    interface Priced
    {
        public function price(): Money;
    }

    enum Currency: string
    {
        case Euro = 'EUR';
    }
    PHP;

it('reads a change inside a body as declaring the same', function () use ($same, $type): void {
    expect($same($type, str_replace('$amount * 100', '$amount * 101', $type)))->toBeTrue()
        ->and($same($type, str_replace('return $this->amount > $floor;', 'return true;', $type)))->toBeTrue()
        ->and($same($type, str_replace('    use Rounds;', "    // Rounds\n    use Rounds;", $type)))->toBeTrue();
});

it('reads a change to a type\'s header, or a statement of its body, as declaring otherwise', function () use (
    $same,
    $type,
): void {
    expect($same($type, str_replace('final class', 'class', $type)))->toBeFalse()
        ->and($same($type, str_replace('#[Attribute]', '#[Other]', $type)))->toBeFalse()
        ->and($same($type, str_replace('implements Stringable', '', $type)))->toBeFalse()
        ->and($same($type, str_replace('use Rounds;', 'use Floors;', $type)))->toBeFalse()
        ->and($same($type, str_replace('SCALE = 2', 'SCALE = 3', $type)))->toBeFalse()
        ->and($same($type, str_replace('$cents = 0', '$cents = 1', $type)))->toBeFalse()
        ->and($same($type, str_replace('private int $amount', 'protected int $amount', $type)))->toBeFalse()
        ->and($same($type, str_replace('int $floor = 0', 'int $floor = 1', $type)))->toBeFalse()
        ->and($same($type, str_replace('public function isPositive', 'protected function isPositive', $type)))
        ->toBeFalse()
        ->and($same($type, str_replace('price(): Money', 'price(): ?Money', $type)))->toBeFalse()
        ->and($same($type, str_replace("'EUR'", "'USD'", $type)))->toBeFalse();
});

it('reads a function\'s signature and a constant outside a type, and a namespace\'s name and body', function () use (
    $same,
): void {
    $functions = <<<'PHP'
        <?php

        namespace App {
            const LIMIT = 10;

            function limited(int $n = LIMIT): int
            {
                return min($n, LIMIT);
            }

            class Inside
            {
                public int $count = 1;
            }
        }
        PHP;

    expect($same($functions, str_replace('min($n, LIMIT)', 'max($n, LIMIT)', $functions)))->toBeTrue()
        ->and($same($functions, str_replace('LIMIT = 10', 'LIMIT = 11', $functions)))->toBeFalse()
        ->and($same($functions, str_replace('int $n = LIMIT', 'int $n = 0', $functions)))->toBeFalse()
        ->and($same($functions, str_replace('$count = 1', '$count = 2', $functions)))->toBeFalse()
        ->and($same($functions, str_replace('namespace App {', 'namespace Other {', $functions)))->toBeFalse();
});

it('reads no statement outside a type as declaring unless it declares, nor `Name::class` as a type', function () use (
    $same,
): void {
    $script = <<<'PHP'
        <?php

        if ($argc > 1) {
            echo 'many';
        }

        echo Money::class;

        $total = 1 + 2;
        PHP;

    expect($same($script, str_replace('1 + 2', '1 - 2', $script)))->toBeTrue()
        ->and($same($script, str_replace('$argc > 1', '$argc >= 1', $script)))->toBeTrue()
        ->and($same($script, str_replace('echo Money::class;', 'echo Other::class;', $script)))->toBeTrue();
});

it('reads a statement inside a braced namespace as declaring only where it declares', function () use ($same): void {
    $script = "<?php\n\nnamespace App {\n    echo 'one';\n\n    final class Money\n    {\n    }\n}\n";

    expect($same($script, str_replace("'one'", "'two'", $script)))->toBeTrue()
        ->and($same($script, str_replace('final class', 'class', $script)))->toBeFalse();
});

it('reads a closure inside a statement as part of it, declaring as the statement does', function () use ($same): void {
    $script = "<?php\n\nregister(function (): int { return 1; });\n\nfinal class Money\n{\n"
        . "    #[Hook(static function (): int { return 1; })]\n    public function add(): int\n    {\n        return 1;\n    }\n}\n";

    expect($same($script, str_replace('register(', 'enlist(', $script)))->toBeTrue()
        ->and($same($script, str_replace('#[Hook(static function (): int { return 1; })]', '#[Hook(static function (): int { return 2; })]', $script)))
        ->toBeFalse();
});
