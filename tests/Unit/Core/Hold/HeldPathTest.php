<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\HeldPath;

it('keeps what a string literal holds, and writes it back in single quotes', function (): void {
    $path = HeldPath::literal('src/Kernel.php');

    expect($path->isLiteral())->toBeTrue()
        ->and($path->text())->toBe('src/Kernel.php')
        ->and($path->written())->toBe("'src/Kernel.php'")
        ->and($path->group())->toBe("'holds:src/Kernel.php'");
});

it('keeps any other expression as it is written, and joins it to the group it names', function (): void {
    $path = HeldPath::expression('self::KERNEL');

    expect($path->isLiteral())->toBeFalse()
        ->and($path->text())->toBe('self::KERNEL')
        ->and($path->written())->toBe('self::KERNEL')
        ->and($path->group())->toBe("'holds:' . self::KERNEL");
});
