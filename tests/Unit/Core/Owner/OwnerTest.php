<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Owner\Owner;
use NightWorksIO\MutationGate\Tests\Support\Owners;

it('reads a user, a team and an email address as GitHub spells them', function (string $token): void {
    $owner = Owner::parse($token);

    expect($owner)->toBeInstanceOf(Owner::class)
        ->and($owner instanceof Owner ? $owner->value() : '')->toBe($token);
})->with([
    'a user' => ['@octocat'],
    'a user with a hyphen' => ['@global-owner1'],
    'a team' => ['@octo-org/octocats'],
    'a team with an underscore and a dot' => ['@acme/pay_ments.eu'],
    'an email address' => ['docs@example.com'],
]);

it('reads nothing else as an owner', function (string $token): void {
    expect(Owner::parse($token))->toBeInstanceOf(NotGiven::class);
})->with([
    'a bare name' => ['octocat'],
    'an at sign alone' => ['@'],
    'a user that starts with a hyphen' => ['@-octocat'],
    'a team with no name' => ['@octo-org/'],
    'a team under a team' => ['@octo-org/octocats/kittens'],
    'an address with no dot in its domain' => ['docs@example'],
    'an address with two at signs' => ['docs@@example.com'],
    'an address with nothing before its at sign' => ['@example.com.'],
    'something before a user' => ['x@octocat y'],
    'something after a user' => ['@octocat!'],
]);

it('is another spelling of an owner only in case', function (): void {
    expect([
        Owners::owner('@acme/Web')->is(Owners::owner('@ACME/web')),
        Owners::owner('Jo@Acme.example')->is(Owners::owner('jo@acme.example')),
        Owners::owner('@acme/web')->is(Owners::owner('@acme/webs')),
    ])->toBe([true, true, false]);
});
