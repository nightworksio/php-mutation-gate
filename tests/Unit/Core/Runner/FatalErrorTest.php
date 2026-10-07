<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\FatalError;

it('reads PHP\'s record of a fatal error, as it prints it, logs it to its error output or logs it to a file', function (string $text): void {
    expect(FatalError::in($text))->toBeTrue();
})->with([
    'printed' => ["PHPUnit 13.3.4\n\nFatal error: Cannot redeclare function helper() in /app/src/helpers.php on line 9\n"],
    'logged to its error output' => ["PHP Fatal error:  Uncaught RuntimeException: boom in /app/tests/bootstrap.php:3\n"],
    'logged to a file' => ["[07-Oct-2026 20:15:01 UTC] PHP Fatal error:  Cannot declare class Money in /app/src/Money.php on line 7\n"],
    'a parse error' => ["PHP Parse error:  syntax error, unexpected token \"}\" in /app/src/Money.php on line 12\n"],
]);

it('reads none where PHPUnit says its process ended mid-test, or the words stand inside a line', function (string $text): void {
    expect(FatalError::in($text))->toBeFalse();
})->with([
    'a process that ended mid-test' => ["Fatal error: Premature end of PHP process when running Tests\\MoneyTest::adds.\n"],
    'errors it hid' => ["Fatal error: Premature end of PHPUnit's PHP process. Use display_errors=On to see the error message.\n"],
    'inside a line' => ["the test printed Fatal error: on purpose\n"],
    'a warning' => ["PHP Warning:  Undefined variable \$x in /app/src/Money.php on line 3\n"],
    'nothing' => [''],
]);
