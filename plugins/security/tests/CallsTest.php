<?php

declare(strict_types=1);

use NightWorksIO\MutationGateSecurity\Calls;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;

it('finds a call of a function named in any case', function (): void {
    $call = new FuncCall(new Name('hash_equals'));

    expect(Calls::ofFunction($call, 'Hash_Equals'))->toBe($call);
});
