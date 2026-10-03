<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Words;

it('reads each word some text spells, lower-cased, once, a hyphenated run joined up and word by word', function (): void {
    expect(Words::in("Exchange-Rates for the_EUR\nrates, 2026-10")->all())
        ->toBe(['exchangerates', 'exchange', 'rates', 'for', 'the_eur', '202610', '2026', '10'])
        ->and(Words::in('-- . / ')->all())->toBe([])
        ->and(Words::in('')->all())->toBe([]);
});
