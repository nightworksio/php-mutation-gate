<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Relocated;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** A config file's JSON, relocated from where it is to the project `/p`. */
$relocated = static function (string $file, string $json): string {
    $document = Relocated::file(Configs::document($json), Path::of($file), '/p');

    return $document instanceof Document ? $document->json() : $document->why();
};

it('leaves a config file in the project\'s own directory as it is', function () use ($relocated): void {
    expect($relocated('/p/mutation-gate.json', '{"trees": [{"path": "../src"}]}'))
        ->toBe('{"trees": [{"path": "../src"}]}');
});

it('reads each path from the file\'s directory', function (string $path, string $from) use ($relocated): void {
    expect($relocated('/p/ci/gate/mutation-gate.json', sprintf('{"baseline": {"path": "%s"}}', $path)))
        ->toBe(sprintf('{"baseline":{"path":"%s"}}', $from));
})->with([
    'beside the file' => ['baseline.json', 'ci/gate/baseline.json'],
    'one directory up' => ['../baseline.json', 'ci/baseline.json'],
    'to the project' => ['../../baseline.json', 'baseline.json'],
    'above the project' => ['../../../baseline.json', '../baseline.json'],
    'through a dot' => ['./x/../baseline.json', 'ci/gate/baseline.json'],
    'absolute' => ['/etc/baseline.json', '/etc/baseline.json'],
]);

it('leaves what is not a path as the file wrote it', function () use ($relocated): void {
    expect($relocated('/p/ci/mutation-gate.json', '{"baseline": {"path": 3}, "trees": "src", "ci": {"gitlab": 1}}'))
        ->toBe('{"baseline":{"path":3},"trees":"src","ci":{"gitlab":1}}')
        ->and($relocated('/p/ci/mutation-gate.json', '{"treeSource": "phpunit", "proofs": {"store": "directory"}}'))
        ->toBe('{"treeSource":"phpunit","proofs":{"store":"directory"}}');
});

it('names a path from the project as a file in another directory would', function (string $file, string $named): void {
    expect(Relocated::fromProject('src', Path::of($file), '/p'))->toBe($named);
})->with([
    'the project\'s own directory' => ['/p/mutation-gate.json', 'src'],
    'a directory in it' => ['/p/ci/mutation-gate.json', '../src'],
    'two directories in it' => ['/p/ci/gate/mutation-gate.json', '../../src'],
    'outside it' => ['/etc/mutation-gate.json', '/p/src'],
    'a directory whose name starts like it' => ['/pp/mutation-gate.json', '/p/src'],
]);
