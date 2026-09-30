<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Mentions;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('reads an enum case\'s value wherever the enum is used, and through self and static inside it', function (): void {
    $read = Mentions::of(Symbol::enumCase('App\Status', 'Paid'), Php::source(<<<'PHP'
        <?php
        namespace App;
        enum Status: string {
            case Paid = 'p';
            public function a(): self { return self::Paid; }
            public function b(): string { return static::Paid->value; }
        }
        class Other { function c() { return self::X; } }
        function f(Status $s) { return Status::from('p'); }
        PHP));

    expect(Php::sites($read))->toBe(['src/A.php:9', 'src/A.php:9', 'src/A.php:5', 'src/A.php:5', 'src/A.php:6'])
        ->and($read->isAmbiguous())->toBeFalse();
});
