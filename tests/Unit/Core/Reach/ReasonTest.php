<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Reach\Reason;

it('is the sentence printed for a decision', function (): void {
    expect(Reason::that('`README.md` reaches nothing by itself.')->text())->toBe('`README.md` reaches nothing by itself.');
});

it('says whether the decision reaches every unit of a package, keeping its sentence', function (): void {
    $reason = Reason::that('`composer.json` decides how the gate runs, so every unit is reached.');

    expect($reason->widens())->toBeFalse()
        ->and($reason->widening()->widens())->toBeTrue()
        ->and($reason->widening()->text())->toBe($reason->text());
});
