<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;

$file = <<<'PHP'
    <?php

    namespace App;

    function free(int $amount, &$rest, int ...$more): int
    {
        return $amount + 1;
    }

    interface Priced
    {
        public function price(): int;
    }

    final class Cart implements Priced
    {
        public function __construct(private array $items)
        {
        }

        final public static function of(array $items): self
        {
            return new self($items);
        }

        public function fits(int $amount, int $limit): bool
        {
            $twice = function (int $x) use ($amount): int {
                return $x * 2;
            };

            return $amount < $limit;
        }

        public function &items(): array
        {
            return $this->items;
        }

        public function price(): int
        {
            return 0;
        }
    }

    $made = new class {
        public function anonymous($value)
        {
            return $value;
        }
    };

    abstract class Shelf
    {
        static public function a(int $x): int
        {
            return $x;
        }

        static protected function b(int $x): int
        {
            return $x;
        }

        static private function c(int $x): int
        {
            return $x;
        }

        static final function d(int $x): int
        {
            return $x;
        }
    }

    trait Counts
    {
        public function count(): int
        {
            return 1;
        }
    }

    enum Size
    {
        case Small;

        public static function smallest(): self
        {
            return self::Small;
        }

        public function for(int $step): self
        {
            return $this;
        }
    }
    PHP;

/** What a test would call for the function around a line of the file, or nothing. */
$called = static function (int $line) use ($file): string {
    $enclosing = Enclosing::in(Contents::of($file), Line::of($line));

    return $enclosing instanceof Enclosing ? $enclosing->call() : 'nothing';
};

it('calls a function by its name, with its parameters\' names, a reference and a variadic among them', function () use ($called): void {
    expect($called(7))->toBe('free($amount, $rest, $more)');
});

it('calls a constructor with new, a static method on its class, and any other method on an object named for it', function () use ($called): void {
    expect($called(18))->toBe('new Cart($items)')
        ->and($called(23))->toBe('Cart::of($items)')
        ->and($called(32))->toBe('$cart->fits($amount, $limit)')
        ->and($called(37))->toBe('$cart->items()');
});

it('calls a method static whatever modifiers stand between static and function, on its class, its trait or its enum, a semi-reserved name among them', function () use ($called): void {
    expect($called(57))->toBe('Shelf::a($x)')
        ->and($called(62))->toBe('Shelf::b($x)')
        ->and($called(67))->toBe('Shelf::c($x)')
        ->and($called(72))->toBe('Shelf::d($x)')
        ->and($called(80))->toBe('$counts->count()')
        ->and($called(90))->toBe('Size::smallest()')
        ->and($called(95))->toBe('$size->for($step)');
});

it('reads a line inside a closure as inside the function around it', function () use ($called): void {
    expect($called(29))->toBe('$cart->fits($amount, $limit)');
});

it('calls a method of an anonymous class as a function, having no class to name', function () use ($called): void {
    expect($called(50))->toBe('anonymous($value)');
});

it('finds nothing around a line in no function, nor in a method with no body', function () use ($called): void {
    expect($called(3))->toBe('nothing')
        ->and($called(12))->toBe('nothing')
        ->and($called(15))->toBe('nothing');
});

it('names the function it found', function () use ($file): void {
    $enclosing = Enclosing::in(Contents::of($file), Line::of(42));

    expect($enclosing instanceof Enclosing ? $enclosing->name() : $enclosing)->toBe('price')
        ->and(Enclosing::in(Contents::of('<?php return 1;'), Line::of(1)))->toBeInstanceOf(Nameless::class);
});

it('reflects a function by its name, and a method by its class and its name', function () use ($file): void {
    $reflected = static function (int $line) use ($file): string {
        $enclosing = Enclosing::in(Contents::of($file), Line::of($line));

        return $enclosing instanceof Enclosing ? $enclosing->reflected() : 'nothing';
    };

    expect($reflected(7))->toBe("new \\ReflectionFunction('free')")
        ->and($reflected(32))->toBe("new \\ReflectionMethod(Cart::class, 'fits')")
        ->and($reflected(23))->toBe("new \\ReflectionMethod(Cart::class, 'of')");
});
