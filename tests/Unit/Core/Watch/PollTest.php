<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\Poll;

describe('Poll', function (): void {
    it('looks once a second', function (): void {
        expect(Poll::interval())->toEqual(Seconds::of(1.0));
    });
})->group('holds:src/Core/Watch/Poll.php');
