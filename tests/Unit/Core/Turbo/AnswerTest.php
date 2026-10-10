<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Turbo\Answer;

it('holds the text the helper printed', function (): void {
    expect(Answer::ofText("{\"keys\":[]}\n")->text())->toBe("{\"keys\":[]}\n");
});
