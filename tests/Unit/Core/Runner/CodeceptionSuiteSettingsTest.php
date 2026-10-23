<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\CodeceptionSuiteSettings;

it('reads a suite as serving the application from another process where a module drives it there or coverage is remote', function (string $settings, bool $elsewhere): void {
    expect(CodeceptionSuiteSettings::servesElsewhere(Node::decode($settings)))->toBe($elsewhere);
})->with([
    'PhpBrowser by name' => ['{"modules":{"enabled":["PhpBrowser"]}}', true],
    'WebDriver with its settings' => ['{"modules":{"enabled":[{"WebDriver":{"url":"http://localhost"}}]}}', true],
    'a module class named in full' => ['{"modules":{"enabled":["\\\\Codeception\\\\Module\\\\PhpBrowser"]}}', true],
    'a module that depends on PhpBrowser' => ['{"modules":{"enabled":[{"REST":{"depends":"PhpBrowser","url":"/"}}]}}', true],
    'remote coverage' => ['{"coverage":{"remote":true}}', true],
    'in-process modules' => ['{"modules":{"enabled":["Asserts",{"Laravel":{"environment_file":".env"}},{"REST":{"depends":"Laravel"}}]}}', false],
    'a module named like one, but not one' => ['{"modules":{"enabled":["MyPhpBrowserHelper"]}}', false],
    'local coverage' => ['{"coverage":{"remote":false}}', false],
    'no settings' => ['{}', false],
]);
