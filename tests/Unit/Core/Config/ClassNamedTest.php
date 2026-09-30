<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ClassNamed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;

it('tells a class from a name once, by its backslash', function (): void {
    expect(Choice::of('Acme\\Gate\\Slack', Options::none())->use())->toEqual(ClassNamed::of('Acme\\Gate\\Slack'))
        ->and(Choice::of('\\Slack', Options::none())->use())->toEqual(ClassNamed::of('\\Slack'))
        ->and(Choice::of('slack', Options::none())->use())->toEqual(Name::of('slack'))
        ->and(ClassNamed::of('Acme\\Gate\\Slack')->value())->toBe('Acme\\Gate\\Slack');
});
