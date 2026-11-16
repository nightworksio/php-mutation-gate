<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\CopyName;

it('names a copy by the original\'s name of word characters, forty bytes of it at most, and the token', function (): void {
    expect(CopyName::of('shop-test.db', 'a1b2'))->toBe('shoptestdb_mg_a1b2')
        ->and(CopyName::of(str_repeat('x', 50), 'a1b2'))->toBe(sprintf('%s_mg_a1b2', str_repeat('x', 40)));
});

it('quotes a name between a mark, doubling each such mark in it', function (): void {
    expect(CopyName::quoted('my`db', '`'))->toBe('`my``db`')
        ->and(CopyName::quoted('plain', '"'))->toBe('"plain"');
});
