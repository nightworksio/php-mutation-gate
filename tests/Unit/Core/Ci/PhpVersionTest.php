<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\PhpVersion;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('sets up the platform PHP, else the lowest the project allows, and never one below the gate\'s', function (
    string $manifest,
    string $version,
): void {
    expect(PhpVersion::in(Node::decode($manifest)))->toBe($version);
})->with([
    'the platform' => ['{"config": {"platform": {"php": "8.6.3"}}, "require": {"php": "^8.5"}}', '8.6'],
    'the lowest required' => ['{"require": {"php": "^8.7 || ^9.1"}}', '8.7'],
    'the lowest, written last' => ['{"require": {"php": "^9.1|^8.6"}}', '8.6'],
    'a major alone' => ['{"require": {"php": ">=9"}}', '9.0'],
    'below the gate\'s' => ['{"require": {"php": "^8.2"}}', '8.5'],
    'a platform below the gate\'s' => ['{"config": {"platform": {"php": "8.1"}}}', '8.5'],
    'nothing asked' => ['{}', '8.5'],
    'nothing a version' => ['{"require": {"php": "*"}}', '8.5'],
]);

it('holds the lowest PHP the gate runs on as its own composer.json requires it', function (): void {
    $required = Node::decode((string) file_get_contents(Tree::at('composer.json')))->field('require')->field('php');

    expect($required->text())->toBe(sprintf('^%s', PhpVersion::LOWEST));
});
