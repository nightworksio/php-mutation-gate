<?php

declare(strict_types=1);

use Library\Fallen;

it('triples a small amount', function (): void {
    expect(new Fallen()->tripled(2))->toBe(6);
});

it('triples while it holds a writable file', function (): void {
    $file = tmpfile();

    expect(new Fallen()->tripled(6))->toBe(18);

    if (is_resource($file)) {
        fwrite($file, 'held');
        fclose($file);
    }
});
