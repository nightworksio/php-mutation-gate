<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\StepTemplate;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

it('passes a step\'s keys on as they are written, with a command or a list of them', function (string $written): void {
    $read = StepTemplate::buildkite()->read(Node::config($written));

    expect($read->problems())->toBe([])
        ->and($read->value())->toEqual(Json::parse($written));
})->with([
    'no command' => ['{"agents": {"queue": "php"}, "env": {"CI": "1"}}'],
    'a command' => ['{"command": "composer install"}'],
    'a list of commands' => ['{"command": ["composer install", "npm ci"], "plugins": []}'],
    'an empty list' => ['{"command": []}'],
]);

it('refuses a command that is not text, or a list of text', function (string $command, string $got): void {
    $read = StepTemplate::buildkite()->read(Node::config(sprintf('{"command": %s}', $command)));

    expect($read->problems())->toEqual([
        Problem::at('command', sprintf('expected a command, or a list of commands, as text, got %s', $got)),
    ]);
})->with([
    'a number' => ['7', '7'],
    'empty text' => ['""', '""'],
    'an object' => ['{"run": "make"}', 'an object'],
    'a list holding a number' => ['["make", 7]', 'a list'],
    'a list holding empty text' => ['[""]', 'a list'],
]);

it('refuses a step that is not an object, as an object would be expected', function (): void {
    expect(StepTemplate::buildkite()->read(Node::config('"make"'))->problems())
        ->toEqual([Problem::at('', 'expected an object, got "make"')]);
});

it('says it expects an object, and writes the command it reads into its schema', function (): void {
    $schema = Json::parse(<<<'JSON'
        {"type": "object", "properties": {"command": {"anyOf": [
            {"type": "string", "minLength": 1},
            {"type": "array", "items": {"type": "string", "minLength": 1}}
        ]}}}
        JSON);

    expect(StepTemplate::buildkite()->expected())->toBe('an object')
        ->and(StepTemplate::buildkite()->schema())->toEqual($schema)
        ->and(StepTemplate::buildkite()->effects())->toBe([]);
});
