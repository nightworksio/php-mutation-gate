<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\PhpOptions;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

it('writes each option as the Option call that writes it: a list, a nested object or one value', function (): void {
    $options = Json::object(
        Member::of('tags', Json::items('a', 'b')),
        Member::of('none', Json::items()),
        Member::of('agents', Json::object(Member::of('queue', 'mutation'))),
        Member::of('soft_fail', value: true),
    );

    expect(PhpOptions::of($options))->toBe([
        "Option::list('tags', 'a', 'b')",
        "Option::list('none')",
        "Option::nested('agents', Option::of('queue', 'mutation'))",
        "Option::of('soft_fail', true)",
    ]);
});
