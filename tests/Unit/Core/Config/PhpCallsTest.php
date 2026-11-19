<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\AdapterBuilder;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

it('chooses an adapter by its builder method where it takes no options, and by uses with each option where it does', function (): void {
    $options = Options::of(Json::object(Member::of('canary', 'mutation-canary')));

    expect([
        PhpCalls::chosen(Choice::of('pest', Options::none()), AdapterBuilder::Runner, 'pest'),
        PhpCalls::chosen(Choice::of('pest', $options), AdapterBuilder::Runner, 'pest'),
        PhpCalls::chosen(Choice::of('acme', Options::none()), AdapterBuilder::Runner, 'pest'),
    ])->toBe(['Runner::pest()', "Runner::uses('pest', Option::of('canary', 'mutation-canary'))", "Runner::uses('acme')"]);
});
