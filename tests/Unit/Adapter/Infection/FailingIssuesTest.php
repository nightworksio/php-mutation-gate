<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\FailingIssues;
use NightWorksIO\MutationGate\Core\NotGiven;

/** The config's root, with these attributes. */
function failingConfig(string $attributes): DOMDocument
{
    $document = new DOMDocument();
    $document->loadXML(sprintf('<phpunit %s/>', $attributes));

    return $document;
}

/** @return array{bool, bool, bool} */
function failingOn(FailingIssues $failing): array
{
    return [$failing->risky(), $failing->warning(), $failing->phpunitWarning()];
}

it('fails on a risky test, a warning and a warning of PHPUnit\'s where the config says nothing of them, as Infection sets it', function (DOMDocument|NotGiven $config): void {
    expect(failingOn(FailingIssues::of($config)))->toBe([true, true, true])
        ->and(failingOn(new FailingIssues()))->toBe([true, true, true]);
})->with([
    'a config that says nothing' => [fn(): DOMDocument => failingConfig('')],
    'no config' => [fn(): NotGiven => NotGiven::value()],
    'a document with no root' => [fn(): DOMDocument => new DOMDocument()],
]);

it('reads what the config sets, in any case', function (string $attributes, array $failing): void {
    expect(failingOn(FailingIssues::of(failingConfig($attributes))))->toBe($failing);
})->with([
    'risky off' => ['failOnRisky="false"', [false, true, true]],
    'warnings off, shouting' => ['failOnWarning="FALSE"', [true, false, true]],
    'PHPUnit\'s warnings off' => ['failOnPhpunitWarning="False"', [true, true, false]],
    'each on' => ['failOnRisky="TRUE" failOnWarning="true" failOnPhpunitWarning="True"', [true, true, true]],
]);

it('keeps PHPUnit\'s default where the config sets an attribute to what PHPUnit cannot read, which Infection leaves alone', function (): void {
    expect(failingOn(FailingIssues::of(failingConfig('failOnRisky="1" failOnWarning="yes" failOnPhpunitWarning="0"'))))
        ->toBe([false, false, true]);
});

it('fails on every issue where the config fails on all of them, whatever else it says', function (): void {
    expect(failingOn(FailingIssues::of(failingConfig('failOnAllIssues="true" failOnRisky="false" failOnWarning="false" failOnPhpunitWarning="false"'))))
        ->toBe([true, true, true])
        ->and(failingOn(FailingIssues::of(failingConfig('failOnAllIssues="false" failOnRisky="false"'))))->toBe([false, true, true])
        ->and(failingOn(FailingIssues::of(failingConfig('failOnAllIssues="on" failOnWarning="false"'))))->toBe([true, false, true]);
});
