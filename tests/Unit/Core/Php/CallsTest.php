<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Calls;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('reads a plain function\'s default at each call as the file resolves the name', function (): void {
    $read = Calls::of(Symbol::parameter('App\tax', 'rate'), Php::source(<<<'PHP'
        <?php
        namespace App;
        use function Other\tax as other;
        tax();
        \App\tax(1);
        other();
        $a->tax();
        A::tax();
        new tax();
        function tax() { }
        $f = tax(...);
        tax;
        PHP));

    expect(Php::sites($read))->toBe(['src/A.php:4', 'src/A.php:5', 'src/A.php:11'])
        ->and($read->isAmbiguous())->toBeFalse();
});
