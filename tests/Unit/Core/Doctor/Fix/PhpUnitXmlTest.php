<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Fix\PhpUnitXml;

it('sets the memory_limit an ini sets, keeping every other line as it was', function (): void {
    $xml = "<phpunit>\n    <php>\n        <ini value=\"-1\" name=\"memory_limit\"/>\n        <ini name=\"error_reporting\" value=\"-1\"/>\n    </php>\n</phpunit>\n";

    expect(PhpUnitXml::memoryLimit($xml, '512M'))
        ->toBe("<phpunit>\n    <php>\n        <ini value=\"512M\" name=\"memory_limit\"/>\n        <ini name=\"error_reporting\" value=\"-1\"/>\n    </php>\n</phpunit>\n")
        ->and(PhpUnitXml::memoryLimit("<phpunit/>\n", '512M'))->toBe("<phpunit/>\n");
});

it('includes each directory its source does not, wherever its source stands', function (string $xml, string $included): void {
    expect(PhpUnitXml::sources($xml, ['src', 'app']))->toBe($included);
})->with([
    'an include holding one of them' => [
        "<phpunit>\n    <source>\n        <include>\n            <directory>src</directory>\n        </include>\n    </source>\n</phpunit>\n",
        "<phpunit>\n    <source>\n        <include>\n            <directory>src</directory>\n            <directory>app</directory>\n        </include>\n    </source>\n</phpunit>\n",
    ],
    'a source with only an exclude' => [
        "<phpunit>\n    <source>\n        <exclude>\n            <directory>x</directory>\n        </exclude>\n    </source>\n</phpunit>\n",
        "<phpunit>\n    <source>\n        <include>\n            <directory>src</directory>\n            <directory>app</directory>\n        </include>\n        <exclude>\n            <directory>x</directory>\n        </exclude>\n    </source>\n</phpunit>\n",
    ],
    'an empty source' => [
        "<phpunit>\n    <source>\n    </source>\n</phpunit>\n",
        "<phpunit>\n    <source>\n        <include>\n            <directory>src</directory>\n            <directory>app</directory>\n        </include>\n    </source>\n</phpunit>\n",
    ],
    'a source holding only spaces' => [
        "<phpunit>\n    <source>  </source>\n</phpunit>\n",
        "<phpunit>\n    <source>\n        <include>\n            <directory>src</directory>\n            <directory>app</directory>\n        </include>\n    </source>\n</phpunit>\n",
    ],
    'a closed source' => [
        "<phpunit>\n    <source/>\n</phpunit>\n",
        "<phpunit>\n    <source>\n        <include>\n            <directory>src</directory>\n            <directory>app</directory>\n        </include>\n    </source>\n</phpunit>\n",
    ],
    'no source, a test suite naming one of them' => [
        "<phpunit>\n    <testsuites><testsuite name=\"unit\"><directory>src</directory></testsuite></testsuites>\n</phpunit>\n",
        "<phpunit>\n    <testsuites><testsuite name=\"unit\"><directory>src</directory></testsuite></testsuites>\n    <source>\n        <include>\n            <directory>src</directory>\n            <directory>app</directory>\n        </include>\n    </source>\n</phpunit>\n",
    ],
]);

it('leaves a source that includes every directory as it is', function (): void {
    $xml = "<phpunit>\n    <source>\n        <include>\n            <directory suffix=\".php\">src/</directory>\n            <directory>app</directory>\n        </include>\n    </source>\n</phpunit>\n";

    expect(PhpUnitXml::sources($xml, ['src', 'app']))->toBe($xml);
});
