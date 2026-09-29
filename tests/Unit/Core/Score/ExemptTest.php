<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\Exempt;

it('carries the reason a tree is not mutated', function (): void {
    expect(Exempt::because('Generated code, regenerated on every build')->reason())->toBe('Generated code, regenerated on every build');
});
