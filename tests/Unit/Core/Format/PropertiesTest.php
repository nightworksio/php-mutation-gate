<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Properties;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reads a key and its value, split by an equals sign, a colon or white space', function (string $line): void {
    expect(Properties::decode($line)->value('sonar.sources'))->toBe('src,app');
})->with([
    'an equals sign' => 'sonar.sources=src,app',
    'a colon' => 'sonar.sources:src,app',
    'white space' => 'sonar.sources src,app',
    'white space around the sign' => "  sonar.sources \t= \fsrc,app",
    'a tab' => "sonar.sources\tsrc,app",
]);

it('keeps the white space a value ends with, and an empty value', function (): void {
    $properties = Properties::decode("a = one \nb=\nc");

    expect($properties->value('a'))->toBe('one ')
        ->and($properties->value('b'))->toBe('')
        ->and($properties->value('c'))->toBe('');
});

it('reads nothing from a comment or a blank line', function (): void {
    $properties = Properties::decode("# a=1\n   ! b=2\n\n   \nc=3\r\nd=4\re=5");

    expect($properties->value('a'))->toEqual(NotGiven::value())
        ->and($properties->value('b'))->toEqual(NotGiven::value())
        ->and($properties->value('# a'))->toEqual(NotGiven::value())
        ->and($properties->value('!'))->toEqual(NotGiven::value())
        ->and($properties->value('c'))->toBe('3')
        ->and($properties->value('d'))->toBe('4')
        ->and($properties->value('e'))->toBe('5');
});

it('lets the later of two equal keys win', function (): void {
    expect(Properties::decode("a=1\na=2")->value('a'))->toBe('2');
});

it('goes on on the next line after a backslash no other escapes, without the white space that line begins with', function (): void {
    $properties = Properties::decode("a = one, \\\n    two, \\\n\t# three\nb = even\\\\\nc = odd\\\\\\\n  more\nd = last\\");

    expect($properties->value('a'))->toBe('one, two, # three')
        ->and($properties->value('b'))->toBe('even\\')
        ->and($properties->value('c'))->toBe('odd\\more')
        ->and($properties->value('d'))->toBe('last');
});

it('unescapes keys and values', function (): void {
    $properties = Properties::decode("a\\ b\\=c\\:d = \\u0041\\t\\n\\r\\f\\x\\\\\\=");

    expect($properties->value('a b=c:d'))->toBe("A\t\n\r\fx\\=");
});

it('reads nothing where the file sets nothing, or a line names no key', function (): void {
    expect(Properties::decode('')->value('a'))->toEqual(NotGiven::value())
        ->and(Properties::decode("=1
b=2")->value(''))->toEqual(NotGiven::value())
        ->and(Properties::decode("=1
b=2")->value('b'))->toBe('2');
});
