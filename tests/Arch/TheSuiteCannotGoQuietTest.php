<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Tree;

// G9: a diagnostic fails the run. Both halves live in phpunit.xml: the failOn
// attributes, and the ignoreSuppressionOf attributes that keep a diagnostic
// raised under `@` from being dropped before it is counted.

/** Every setting either half needs, with the value it needs. */
const THE_SETTINGS = [
    'failOnRisky' => 'true',
    'failOnWarning' => 'true',
    'failOnDeprecation' => 'true',
    'failOnNotice' => 'true',
    'failOnPhpunitDeprecation' => 'true',
    'failOnPhpunitNotice' => 'true',
    'failOnPhpunitWarning' => 'true',
    'beStrictAboutOutputDuringTests' => 'true',
    'ignoreSuppressionOfDeprecations' => 'true',
    'ignoreSuppressionOfPhpDeprecations' => 'true',
    'ignoreSuppressionOfErrors' => 'true',
    'ignoreSuppressionOfNotices' => 'true',
    'ignoreSuppressionOfPhpNotices' => 'true',
    'ignoreSuppressionOfWarnings' => 'true',
    'ignoreSuppressionOfPhpWarnings' => 'true',
];

it('fails the run on every diagnostic', function (): void {
    $settings = (string) file_get_contents(Tree::at('phpunit.xml'));
    $wrong = [];

    foreach (THE_SETTINGS as $name => $value) {
        if (preg_match(sprintf('/\b%s="%s"/u', $name, $value), $settings) !== 1) {
            $wrong[] = sprintf('%s is not "%s"', $name, $value);
        }
    }

    // G9
    expect($wrong)->toBe([], sprintf(
        "phpunit.xml lets a diagnostic pass:\n  %s\n\nA warning the run prints and does not fail on is one nobody reads (G9).",
        implode("\n  ", $wrong),
    ));
});
