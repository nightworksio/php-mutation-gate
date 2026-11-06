<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hint\Attempt;
use NightWorksIO\MutationGate\Core\Hint\Attempts;
use NightWorksIO\MutationGate\Core\Hint\Comparison;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

const ATTEMPTED_CART = <<<'PHP'
    <?php

    final class Cart
    {
        private const int LIMIT = 10;

        public function fits(int $amount, int $limit): bool
        {
            return $amount < self::LIMIT;
        }
    }

    PHP;

const ATTEMPTED_STATUS = <<<'PHP'
    <?php

    enum Status: string
    {
        case Active = 'active';
    }

    PHP;

/**
 * What rules make of a mutant of a line of `src/Cart.php` that changed one line to another, read with or without
 * its file: the sentence and each call's arguments.
 *
 * @return array{}|array{string, list<array<string, string>>} nothing where rules find nothing
 */
function attempted(MutatorFamily $family, string $removed, string $added, int $line = 9, string $source = ATTEMPTED_CART): array
{
    $mutant = Verdicts::mutant(sprintf('src/Cart.php:%d', $line), 'M', $family, Verdicts::diff($removed, $added));
    $read = $source === '' ? Missing::at(Path::of('src/Cart.php')) : Contents::of($source);
    $attempt = Attempts::for($mutant, $read);

    return $attempt instanceof Attempt ? [$attempt->text(), $attempt->calls()] : [];
}

it('tries a boundary at its value and one step past it, a constant\'s value read from its file', function (): void {
    expect(attempted(MutatorFamily::Boundary, 'return $amount < self::LIMIT;', 'return $amount <= self::LIMIT;'))
        ->toBe(['call `fits()` with `$amount` at 10, then at 9.', [['$amount' => '10'], ['$amount' => '9']]])
        ->and(attempted(MutatorFamily::Boundary, 'return 10 >= $amount;', 'return 10 > $amount;'))
        ->toBe(['call `fits()` with `$amount` at 10, then at 11.', [['$amount' => '10'], ['$amount' => '11']]])
        ->and(attempted(MutatorFamily::Boundary, 'return $amount > -2;', 'return $amount >= -2;'))
        ->toBe(['call `fits()` with `$amount` at -2, then at -1.', [['$amount' => '-2'], ['$amount' => '-1']]])
        ->and(attempted(MutatorFamily::Boundary, 'return $amount >= 4;', 'return $amount > 4;'))
        ->toBe(['call `fits()` with `$amount` at 4, then at 3.', [['$amount' => '4'], ['$amount' => '3']]]);
});

it('uses a variable that is no parameter, or one in no function it can read, and makes no call', function (): void {
    expect(attempted(MutatorFamily::Boundary, 'return $total > 5;', 'return $total >= 5;'))
        ->toBe(['use `$total` at 5, then at 6.', []])
        ->and(attempted(MutatorFamily::Boundary, 'return $amount <= 5;', 'return $amount < 5;', source: ''))
        ->toBe(['use `$amount` at 5, then at 6.', []])
        ->and(attempted(MutatorFamily::Boundary, 'return $amount < self::LIMIT;', 'return $amount <= self::LIMIT;', source: ''))
        ->toBe([]);
});

it('tries nothing at a boundary between two variables, or one it reads no value of', function (): void {
    expect(attempted(MutatorFamily::Boundary, 'return $amount < $limit;', 'return $amount <= $limit;'))->toBe([])
        ->and(attempted(MutatorFamily::Boundary, 'return $amount < self::NONE;', 'return $amount <= self::NONE;'))->toBe([])
        ->and(attempted(MutatorFamily::Boundary, 'return count($a) < 2;', 'return count($a) <= 2;'))->toBe([])
        ->and(attempted(MutatorFamily::Boundary, 'return $a;', 'return !$a;'))->toBe([]);
});

it('tries an equality at the value it compares, then at any other', function (): void {
    expect(attempted(MutatorFamily::Condition, 'return $amount === \'gift\';', 'return $amount !== \'gift\';'))
        ->toBe(['call `fits()` with `$amount` at \'gift\', then at any other value.', [['$amount' => '\'gift\'']]])
        ->and(attempted(MutatorFamily::Condition, 'return null !== $limit;', 'return null === $limit;'))
        ->toBe(['call `fits()` with `$limit` at null, then at any other value.', [['$limit' => 'null']]])
        ->and(attempted(MutatorFamily::Condition, 'return $amount == 4;', 'return $amount != 4;'))
        ->toBe(['call `fits()` with `$amount` at 4, then at any other value.', [['$amount' => '4']]])
        ->and(attempted(MutatorFamily::Condition, 'return $amount != 4;', 'return $amount == 4;'))
        ->toBe(['call `fits()` with `$amount` at 4, then at any other value.', [['$amount' => '4']]])
        ->and(attempted(MutatorFamily::Condition, 'return $amount === $limit;', 'return $amount !== $limit;'))->toBe([])
        ->and(attempted(MutatorFamily::Condition, 'if ($amount) {', 'if (!$amount) {'))->toBe([]);
});

it('tries arithmetic with operands that are neither 0, 1 nor each other', function (): void {
    expect(attempted(MutatorFamily::Arithmetic, 'return $amount + $limit;', 'return $amount - $limit;'))
        ->toBe(['call `fits()` with `$amount` at 3 and `$limit` at 5.', [['$amount' => '3', '$limit' => '5']]])
        ->and(attempted(MutatorFamily::Arithmetic, 'return $amount * 3;', 'return $amount / 3;'))
        ->toBe(['call `fits()` with `$amount` at 5.', [['$amount' => '5']]])
        ->and(attempted(MutatorFamily::Arithmetic, 'return $amount / 3;', 'return $amount * 3;'))
        ->toBe(['call `fits()` with `$amount` at 5.', [['$amount' => '5']]])
        ->and(attempted(MutatorFamily::Arithmetic, 'return $amount % 3;', 'return $amount * 3;'))
        ->toBe(['call `fits()` with `$amount` at 5.', [['$amount' => '5']]])
        ->and(attempted(MutatorFamily::Arithmetic, 'return $amount ** 3;', 'return $amount * 3;'))
        ->toBe(['call `fits()` with `$amount` at 5.', [['$amount' => '5']]])
        ->and(attempted(MutatorFamily::Arithmetic, 'return 2 - $limit;', 'return 2 + $limit;'))
        ->toBe(['call `fits()` with `$limit` at 3.', [['$limit' => '3']]])
        ->and(attempted(MutatorFamily::Arithmetic, 'return $amount + $this->tax;', 'return $amount - $this->tax;'))->toBe([])
        ->and(attempted(MutatorFamily::Arithmetic, 'return 2 + 3;', 'return 2 - 3;'))->toBe([]);
});

it('tries a logical operator between two variables with exactly one of them true, either way round', function (): void {
    expect(attempted(MutatorFamily::Logical, 'return $amount && $limit;', 'return $amount || $limit;'))->toBe([
        'call `fits()` with `$amount` at true and `$limit` at false, then the other way round.',
        [['$amount' => 'true', '$limit' => 'false'], ['$amount' => 'false', '$limit' => 'true']],
    ])
        ->and(attempted(MutatorFamily::Logical, 'if ($open || $paid) {', 'if ($open && $paid) {', source: ''))
        ->toBe(['use `$open` at true and `$paid` at false, then the other way round.', []])
        ->and(attempted(MutatorFamily::Logical, 'return $amount and $limit;', 'return $amount or $limit;'))->toBe([
            'call `fits()` with `$amount` at true and `$limit` at false, then the other way round.',
            [['$amount' => 'true', '$limit' => 'false'], ['$amount' => 'false', '$limit' => 'true']],
        ])
        ->and(attempted(MutatorFamily::Logical, 'return $amount or $limit;', 'return $amount and $limit;'))->toBe([
            'call `fits()` with `$amount` at true and `$limit` at false, then the other way round.',
            [['$amount' => 'true', '$limit' => 'false'], ['$amount' => 'false', '$limit' => 'true']],
        ])
        ->and(attempted(MutatorFamily::Logical, 'return $amount && ready();', 'return $amount || ready();'))->toBe([])
        ->and(attempted(MutatorFamily::Logical, 'return ready();', 'return !ready();'))->toBe([]);
});

it('asserts a class constant or a backed case by its name, as its file declares it, and no trait\'s', function (): void {
    expect(attempted(MutatorFamily::Literal, 'private const int LIMIT = 10;', 'private const int LIMIT = 11;', 5))
        ->toBe(['assert `Cart::LIMIT` is `10`.', []])
        ->and(attempted(MutatorFamily::Literal, "case Active = 'active';", "case Active = '';", 5, ATTEMPTED_STATUS))
        ->toBe(['assert `Status::Active->value` is `\'active\'`.', []])
        ->and(attempted(MutatorFamily::Literal, 'private const int LIMIT = 10;', 'private const int LIMIT = 11;', 5, ''))->toBe([])
        ->and(attempted(MutatorFamily::Literal, 'return 10;', 'return 11;'))->toBe([])
        ->and(attempted(MutatorFamily::Literal, 'private const int LIMIT = 10;', 'private const int LIMIT = 11;', 1))->toBe([])
        ->and(attempted(MutatorFamily::Literal, "case Active = 'active';", "case Active = '';", 5, ''))->toBe([])
        ->and(attempted(MutatorFamily::Literal, 'case Small = 1;', 'case Small = 2;', 2, "<?php\nenum Size: int { case Small = 1; }\n"))
        ->toBe(['assert `Size::Small->value` is `1`.', []])
        ->and(attempted(MutatorFamily::Literal, 'const int MAX = 10;', 'const int MAX = 11;', 4, "<?php\ninterface Limits\n{\n    const int MAX = 10;\n}\n"))
        ->toBe(['assert `Limits::MAX` is `10`.', []])
        ->and(attempted(MutatorFamily::Literal, 'const int MAX = 5;', 'const int MAX = 6;', 4, "<?php\nfinal class Cart {}\ntrait Capped\n{\n    const int MAX = 5;\n}\n"))
        ->toBe([]);
});

it('expects the call to return a literal it returned, in a function it can read', function (): void {
    expect(attempted(MutatorFamily::ReturnValue, 'return true;', 'return false;'))
        ->toBe(['expect `$cart->fits($amount, $limit)` to return `true`.', []])
        ->and(attempted(MutatorFamily::ReturnValue, 'return $amount;', 'return null;'))->toBe([])
        ->and(attempted(MutatorFamily::ReturnValue, 'yield true;', 'yield false;'))->toBe([])
        ->and(attempted(MutatorFamily::ReturnValue, 'return true;', 'return false;', source: ''))->toBe([]);
});

it('gives a test double that expects a removed call on a collaborator', function (): void {
    expect(attempted(MutatorFamily::RemovedCall, '$this->logger->info(\'paid\');', ''))
        ->toBe(['replace `$this->logger` with a test double that expects `info()`, then call `fits()`.', []])
        ->and(attempted(MutatorFamily::RemovedCall, '$mailer->send($message);', '', source: ''))
        ->toBe(['pass a test double as `$mailer` that expects `send()`.', []])
        ->and(attempted(MutatorFamily::RemovedCall, 'save();', ''))->toBe([]);
});

it('tries nothing for a family no rule reads a value of', function (MutatorFamily $family): void {
    expect(attempted($family, 'return $amount < 10;', 'return $amount <= 10;'))->toBe([]);
})->with([
    MutatorFamily::Collection,
    MutatorFamily::Exception,
    MutatorFamily::Unwrap,
    MutatorFamily::Visibility,
    MutatorFamily::None,
    MutatorFamily::Unknown,
]);

it('gives a survivor\'s hint its attempt, and no other judgement\'s', function (): void {
    $mutant = Verdicts::mutant('src/Cart.php:9', 'M', MutatorFamily::Boundary, Verdicts::diff('return $amount < 4;', 'return $amount <= 4;'));
    $hint = static fn(MutantJudgement $judgement): Hint => Hint::for($mutant, $judgement, TestIds::none(), Contents::of(ATTEMPTED_CART), NoFinding::survivor(), TestNames::none());

    expect($hint(MutantJudgement::Survived)->attempt())->toBe('call `fits()` with `$amount` at 4, then at 3.')
        ->and($hint(MutantJudgement::Killed)->attempt())->toBeInstanceOf(NotGiven::class)
        ->and(Hint::that('x')->attempt())->toBeInstanceOf(NotGiven::class);
});

it('swaps each comparison\'s sides, and steps past each boundary on the side its mutant flips', function (): void {
    expect(array_map(static fn(Comparison $comparison): string => $comparison->mirrored()->value, Comparison::cases()))->toBe(['>', '>=', '<', '<='])
        ->and(array_map(static fn(Comparison $comparison): int => $comparison->pastOf(10), Comparison::cases()))->toBe([9, 11, 11, 9])
        ->and(Comparison::spelt())->toBe(['<', '<=', '>', '>=']);
});
