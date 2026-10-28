<?php

declare(strict_types=1);

use Library\Code;
use Library\Codes;

it('decides an admitted code', function (): void {
    expect(Codes::isDecided(Code::Admitted))->toBeTrue();
});

it('places every code in exactly one list', function (): void {
    expect([...Codes::DECIDED, ...Codes::ANSWERED])->toBe([Code::Admitted, Code::Refused, Code::Moved]);
});
