<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Http\Token;

it('is carried as a bearer token', function (): void {
    expect(Token::bearer('ya29.secret')->authorization())->toBe('Bearer ya29.secret');
});
