<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\PrematureEnd;

$hidden = "Fatal error: Premature end of PHPUnit's PHP process. Use display_errors=On to see the error message.";
$ended = "PHPUnit 13.3.4\n\nFatal error: Premature end of PHP process when running Tests\\MoneySpec::adds.\n";

it('reads PHPUnit saying it hid the error as hidden, whatever the config says', function () use ($hidden): void {
    expect(PrematureEnd::hidingIn(sprintf("before\n%s\nafter", $hidden), configuredToHide: false))->toBeTrue();
});

it('reads PHPUnit saying the process ended mid-test as hidden only where the config hides errors', function () use ($ended): void {
    expect(PrematureEnd::hidingIn($ended, configuredToHide: true))->toBeTrue()
        ->and(PrematureEnd::hidingIn($ended, configuredToHide: false))->toBeFalse();
});

it('reads no hiding in output that says neither, or only part of the sentence', function (string $output): void {
    expect(PrematureEnd::hidingIn($output, configuredToHide: true))->toBeFalse();
})->with([
    'a passing run' => ['OK (1 test, 1 assertion)'],
    'no test named' => ['Fatal error: Premature end of PHP process when running .'],
    'the sentence cut short' => ['Fatal error: Premature end of PHP process when running Tests\MoneySpec::adds'],
]);
