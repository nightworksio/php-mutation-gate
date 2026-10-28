<?php

declare(strict_types=1);

use Library\Compared;

it('compares a token in constant time, as its file says', function (): void {
    $file = (string) file_get_contents(sprintf('%s/src/Compared.php', dirname(__DIR__)));

    expect(new Compared()->same('token', 'token'))->toBeTrue()
        ->and($file)->toContain('return hash_equals($known, $user);');
});
