<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\ClosedSets\StringSets;
use PhpParser\Node\Expr\Match_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;

/** The first match in a snippet. */
function firstMatchIn(string $code): Match_
{
    $match = new NodeFinder()->findFirstInstanceOf(new ParserFactory()->createForHostVersion()->parse($code) ?? [], Match_::class);

    return $match instanceof Match_ ? $match : throw new RuntimeException('The snippet holds no match.');
}

/**
 * A constant array of these entries.
 *
 * @param array<int|string, int|string> $entries
 */
function constantArray(array $entries): Type
{
    $builder = ConstantArrayTypeBuilder::createEmpty();

    foreach ($entries as $key => $value) {
        $builder->setOffsetValueType(
            is_int($key) ? new ConstantIntegerType($key) : new ConstantStringType($key),
            is_int($value) ? new ConstantIntegerType($value) : new ConstantStringType($value),
        );
    }

    return $builder->getArray();
}

it('counts the distinct strings a match compares with, and nothing else', function (): void {
    expect(StringSets::inMatch(firstMatchIn(<<<'PHP_WRAP'
    <?php
    $kind = match ($word) {
        'added', 'copied' => 1,
        'deleted' => 2,
        'added' => 3,
        PHP_EOL => 4,
        default => 0,
    };
    PHP_WRAP)))->toBe(3)
        ->and(StringSets::inMatch(firstMatchIn('<?php $x = match (true) { $a => 1, default => 2 };')))->toBe(0);
});

it('counts the distinct strings among a constant array\'s values, where every value is one', function (): void {
    expect(StringSets::amongValues(constantArray(['push', 'schedule', 'workflow_dispatch', 'push'])))->toBe(3)
        ->and(StringSets::amongValues(constantArray(['push', 'schedule', 3])))->toBe(0)
        ->and(StringSets::amongValues(constantArray([])))->toBe(0);
});

it('counts the distinct strings among a constant array\'s keys, where every key is one', function (): void {
    expect(StringSets::amongKeys(constantArray(['trees' => 1, 'runner' => 2, 'ci.plan' => 3])))->toBe(3)
        ->and(StringSets::amongKeys(constantArray(['trees', 'runner', 'ci.plan'])))->toBe(0);
});
