<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Declarations;
use NightWorksIO\MutationGate\Adapter\Opcache\Uncompiled;
use NightWorksIO\MutationGate\Core\File\Contents;

const DECLARING = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App;

    enum Size: int { case Small = 1; }

    #[\Attribute]
    final class Tag { public function __construct(public int $n = 1) {} }

    /** Counts. */
    final class Counter
    {
        public const LIMIT = 10;
        public const AT = __LINE__;
        public int $count = 3;
        public static array $seen = [1, 2];
        public int $hooked { get => $this->count * 2; }

        #[Tag(5)]
        public function run(int $a = 4, int ...$rest): int
        {
            static $calls = 7;
            $double = fn(int $x): int => $x * 2;
            $named = new class { public const KIND = 'named'; };
            $closure = static function (int $y): int { return $y + 1; };

            return $double($a) + $closure($a);
        }

        private function hidden(): int { return __LINE__; }
    }

    function top(int $a): int { return $a + 1; }

    interface Sized
    {
        public int $size { get; }
    }

    final class Box implements Sized
    {
        public int $size { get { return 1 + 1; } }
    }
    PHP;

/** Whether a program with one change declares what DECLARING declares. */
function declaresTheSame(string $from, string $to): bool
{
    $before = Declarations::of(Contents::of(DECLARING));
    $after = Declarations::of(Contents::of(str_replace($from, $to, DECLARING)));

    return $before instanceof Declarations && $after instanceof Declarations && $before->same($after);
}

it('declares the same where only what a body runs changed, as the opcodes show', function (string $from, string $to): void {
    expect(DECLARING)->toContain($from)
        ->and(declaresTheSame($from, $to))->toBeTrue();
})->with([
    'a statement' => ['return $double($a) + $closure($a);', 'return $double($a) - $closure($a);'],
    'an arrow function\'s body' => ['=> $x * 2;', '=> $x + $x;'],
    'a closure\'s body' => ['return $y + 1;', 'return $y + 2;'],
    'a short hook\'s body' => ['get => $this->count * 2;', 'get => $this->count * 3;'],
    'a function\'s body' => ['return $a + 1; }', 'return $a - 1; }'],
    'the layout of a line' => ['final class Counter', 'final  class   Counter'],
    'a hook\'s block' => ['get { return 1 + 1; }', 'get { return 2; }'],
]);

it('declares something else where a declaration changed, which the opcodes do not show', function (string $from, string $to): void {
    expect(DECLARING)->toContain($from)
        ->and(declaresTheSame($from, $to))->toBeFalse();
})->with([
    'a class constant' => ['LIMIT = 10', 'LIMIT = 11'],
    'a property default' => ['$count = 3', '$count = 4'],
    'a static property default' => ['[1, 2]', '[1, 3]'],
    'an enum case' => ['Small = 1', 'Small = 2'],
    'an attribute\'s argument' => ['Tag(5)', 'Tag(6)'],
    'a parameter default' => ['int $a = 4', 'int $a = 5'],
    'a static variable' => ['static $calls = 7', 'static $calls = 8'],
    'an anonymous class\'s constant' => ["KIND = 'named'", "KIND = 'other'"],
    'a closure\'s signature' => ['static function (int $y)', 'static function (float $y)'],
    'an arrow function\'s signature' => ['fn(int $x): int', 'fn(int $x): float'],
    'a method\'s visibility' => ['private function hidden', 'protected function hidden'],
    'a class\'s modifier' => ['final class Counter', 'class Counter'],
    'a doc comment' => ['/** Counts. */', '/** Counts more. */'],
    'a function\'s signature' => ['function top(int $a): int', 'function top(?int $a): int'],
    'an abstract hook' => ['public int $size { get; }', 'public int $size { set; }'],
    'a line a declaration names' => ['public const LIMIT = 10;', "public const LIMIT = 10;\n"],
]);

it('proves nothing of a program that does not parse', function (): void {
    expect(Declarations::of(Contents::of('<?php function (')))->toBe(Uncompiled::Failed);
});
