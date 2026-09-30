<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;

it('reads a number as a CI spells it', function (): void {
    $number = PullRequestNumber::parse('42');

    expect($number instanceof PullRequestNumber ? $number->value() : 0)->toBe(42)
        ->and(PullRequestNumber::of(42))->toEqual($number);
});

it('says why what a CI names is no number of a pull request', function (string $written): void {
    expect(PullRequestNumber::parse($written))
        ->toEqual(CannotTell::because(sprintf('"%s" is not the number of a pull request.', $written)));
})->with([
    'a word' => ['twelve'],
    'nothing' => [''],
    'zero' => ['0'],
    'below zero' => ['-3'],
    'a leading zero' => ['012'],
    'a sign' => ['+12'],
    'a space' => [' 12'],
    'false, as Buildkite writes none' => ['false'],
]);

it('takes a number from a payload only where it is 1 or more', function (): void {
    expect(PullRequestNumber::of(0))->toEqual(CannotTell::because('"0" is not the number of a pull request.'));
});
