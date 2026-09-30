<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Into;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Config\Layer;

it('expects what the shape it reads expects', function (): void {
    expect(Into::of(Text::of('a branch'), static fn(): Layer => Layer::none())->expected())->toBe('a branch');
});
