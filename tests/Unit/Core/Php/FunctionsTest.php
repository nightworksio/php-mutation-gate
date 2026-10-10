<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Php\Functions;
use NightWorksIO\MutationGate\Core\Php\Nameless;

$file = <<<'PHP'
    <?php

    interface Priced
    {
        public function price(): int;
    }

    final class Cart implements Priced
    {
        public function price(): int
        {
            $sum = array_sum(array_map(function (int $x): int {
                return $x * 2;
            }, [1, 2]));

            return $sum;
        }

        public function &items(): array
        {
            function helper(): string
            {
                return "{$this->name}";
            }

            return [];
        }
    }

    function outside(): void
    {
    }

    enum Size
    {
        case Small;

        public function and(self $other): self
        {
            return $other;
        }
    }

    function label(string $name): string
    {
        $text = "${name} is";

        return $text;
    }

    PHP;

it('names the innermost named function a line is in', function (int $line, string|Nameless $function) use ($file): void {
    expect(Functions::in(Contents::of($file))->around(Line::of($line)))->toEqual($function);
})->with([
    'an interface method, which has no body' => [5, fn(): Nameless => Nameless::code()],
    'the class around a method' => [8, fn(): Nameless => Nameless::code()],
    'the line a method is declared on' => [10, 'price'],
    'a line of its body' => [16, 'price'],
    'inside a closure, the function around it' => [13, 'price'],
    'its closing brace' => [17, 'price'],
    'a method returning by reference' => [19, 'items'],
    'a function declared inside it, with a brace in a string' => [23, 'helper'],
    'the method again after it' => [26, 'items'],
    'a function outside any class' => [30, 'outside'],
    'a method named with a semi-reserved word' => [40, 'and'],
    'past a variable in braces in a string' => [48, 'label'],
    'past the end' => [60, fn(): Nameless => Nameless::code()],
]);

it('reads a file that is not PHP, or closes more than it opens, as declaring nothing', function (): void {
    expect(Functions::in(Contents::of('just text'))->around(Line::of(1)))->toEqual(Nameless::code())
        ->and(Functions::in(Contents::of("<?php\n}\n}\n"))->around(Line::of(2)))->toEqual(Nameless::code());
});

it('gives the line the innermost named function a line is in begins on, which tells two of one name apart', function (int $line, Line|Nameless $start) use ($file): void {
    expect(Functions::in(Contents::of($file))->startAround(Line::of($line)))->toEqual($start);
})->with([
    'a line of a method' => [16, fn(): Line => Line::of(10)],
    'inside a closure, the method around it' => [13, fn(): Line => Line::of(10)],
    'a function declared inside a method' => [23, fn(): Line => Line::of(21)],
    'the method again after it' => [26, fn(): Line => Line::of(19)],
    'a function outside any class' => [30, fn(): Line => Line::of(30)],
    'the class around a method' => [8, fn(): Nameless => Nameless::code()],
    'a method named with a semi-reserved word' => [40, fn(): Line => Line::of(38)],
    'past a variable in braces in a string' => [48, fn(): Line => Line::of(44)],
    'past the end' => [60, fn(): Nameless => Nameless::code()],
]);
