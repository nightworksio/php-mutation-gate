<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Written;

it('says where it wrote', function (): void {
    expect(Written::to('build/mutation.sarif')->where())->toBe('build/mutation.sarif');
});
