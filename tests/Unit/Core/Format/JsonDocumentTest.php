<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Format\JsonFragment;
use NightWorksIO\MutationGate\Core\Migration\KeyPath;
use NightWorksIO\MutationGate\Core\NotGiven;

/** A config as a person lays it out: four spaces, an inline object, a string with an escaped quote and a multibyte character. */
const JSON_LAID_OUT = <<<'JSON'
    {
        "$schema": "vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
        "runner": "pest",
        "reach": {
            "everything": ["composer.lock", "src/\"Ünïcode\".php"],
            "hotPath": 0.5
        },
        "shards": { "seconds": 600 }
    }

    JSON;

function jsonLaidOut(): JsonDocument
{
    $document = JsonDocument::parse(JSON_LAID_OUT);

    return $document instanceof JsonDocument ? $document : throw new LogicException('Not JSON.');
}

it('reads a JSON object, and refuses text that is not JSON, or JSON that is no object', function (): void {
    expect(jsonLaidOut()->text())->toBe(JSON_LAID_OUT)
        ->and(JsonDocument::parse('{"a": '))->toEqual(CannotJudge::because('Syntax error.'))
        ->and(JsonDocument::parse('[1, 2]'))->toEqual(CannotJudge::because('The file holds JSON, but not an object.'));
});

it('finds a key at any depth, and gives its value as written, indented from its key\'s line', function (): void {
    $document = jsonLaidOut();
    $reach = $document->fragment(KeyPath::of('reach'));

    expect([$document->has(KeyPath::of('reach.hotPath')), $document->has(KeyPath::of('reach.none')), $document->has(KeyPath::of('runner.deeper'))])
        ->toBe([true, false, false])
        ->and($reach instanceof JsonFragment ? $reach->text() : $reach)->toBe(<<<'JSON'
            {
                "everything": ["composer.lock", "src/\"Ünïcode\".php"],
                "hotPath": 0.5
            }
            JSON)
        ->and($document->fragment(KeyPath::of('shards.seconds')))->toEqual(JsonFragment::of('600'))
        ->and($document->fragment(KeyPath::of('gone')))->toEqual(NotGiven::value());
});

it('renames a key where it stands, and leaves the file as it was where there is no such key', function (): void {
    $renamed = jsonLaidOut()->renamed(KeyPath::of('reach.hotPath'), KeyPath::of('reach.hotShare'));

    expect($renamed->text())->toBe(str_replace('"hotPath"', '"hotShare"', JSON_LAID_OUT))
        ->and(jsonLaidOut()->renamed(KeyPath::of('reach.none'), KeyPath::of('reach.other'))->text())->toBe(JSON_LAID_OUT);
});

it('removes a first, a later and an only member with the comma and space that go with it, and each object it empties', function (): void {
    expect(jsonLaidOut()->without(KeyPath::of('$schema'))->text())->toBe(<<<'JSON'
        {
            "runner": "pest",
            "reach": {
                "everything": ["composer.lock", "src/\"Ünïcode\".php"],
                "hotPath": 0.5
            },
            "shards": { "seconds": 600 }
        }

        JSON)
        ->and(jsonLaidOut()->without(KeyPath::of('reach.hotPath'))->text())
        ->toContain("\"everything\": [\"composer.lock\", \"src/\\\"Ünïcode\\\".php\"]\n    },")
        ->and(jsonLaidOut()->without(KeyPath::of('shards.seconds'))->text())->toEndWith("\"hotPath\": 0.5\n    }\n}\n")
        ->and(jsonLaidOut()->without(KeyPath::of('reach.none'))->text())->toBe(JSON_LAID_OUT)
        ->and(jsonLaidOut()->without(KeyPath::of('runner.deeper'))->text())->toBe(JSON_LAID_OUT);
});

it('replaces a value where it stands, and adds a key after the last member of the deepest object that holds its path, in that object\'s layout', function (): void {
    $object = JsonFragment::of("{\n    \"seconds\": 5\n}");

    expect(jsonLaidOut()->with(KeyPath::of('runner'), JsonFragment::of('"phpunit"'))->text())
        ->toBe(str_replace('"pest"', '"phpunit"', JSON_LAID_OUT))
        ->and(jsonLaidOut()->with(KeyPath::of('reach.packages'), JsonFragment::of('["packages/*"]'))->text())
        ->toContain("\"hotPath\": 0.5,\n        \"packages\": [\"packages/*\"]\n    },")
        ->and(jsonLaidOut()->with(KeyPath::of('shards.most'), JsonFragment::of('8'))->text())
        ->toContain('"shards": { "seconds": 600, "most": 8 }')
        ->and(jsonLaidOut()->with(KeyPath::of('timeouts.limits'), $object)->text())->toEndWith(<<<'JSON'
            "shards": { "seconds": 600 },
            "timeouts": {
                "limits": {
                    "seconds": 5
                }
            }
        }

        JSON)
        ->and(jsonLaidOut()->with(KeyPath::of('runner.deeper'), JsonFragment::of('1'))->text())->toBe(JSON_LAID_OUT);
});

it('fills an empty object with the key, a level deeper than the line it opens on', function (): void {
    $empty = JsonDocument::parse("{\n  \"reach\": {}\n}\n");

    expect($empty instanceof JsonDocument ? $empty->with(KeyPath::of('reach.hotPath'), JsonFragment::of('0.5'))->text() : $empty)
        ->toBe("{\n  \"reach\": {\n    \"hotPath\": 0.5\n  }\n}\n")
        ->and(JsonDocument::parse('{}') instanceof JsonDocument ? JsonDocument::parse('{}')->with(KeyPath::of('a'), JsonFragment::of('1'))->text() : null)
        ->toBe("{\n    \"a\": 1\n}");
});

it('moves a key and its value as written to another place, and leaves the file as it was where the value cannot go there', function (): void {
    expect(jsonLaidOut()->moved(KeyPath::of('reach.everything'), KeyPath::of('scope.everything'))->text())->toEndWith(<<<'JSON'
            "reach": {
                "hotPath": 0.5
            },
            "shards": { "seconds": 600 },
            "scope": {
                "everything": ["composer.lock", "src/\"Ünïcode\".php"]
            }
        }

        JSON)
        ->and(jsonLaidOut()->moved(KeyPath::of('reach.hotPath'), KeyPath::of('runner.hotPath'))->text())->toBe(JSON_LAID_OUT)
        ->and(jsonLaidOut()->moved(KeyPath::of('gone'), KeyPath::of('elsewhere'))->text())->toBe(JSON_LAID_OUT);
});

it('refuses JSON whose top is a scalar, and gives a value whose later lines stand left of its key as they stand', function (): void {
    $document = JsonDocument::parse("{\n    \"a\": [\n1\n    ]\n}");
    $value = $document instanceof JsonDocument ? $document->fragment(KeyPath::of('a')) : $document;

    expect(JsonDocument::parse('5'))->toEqual(CannotJudge::because('The file holds JSON, but not an object.'))
        ->and($value instanceof JsonFragment ? $value->text() : $value)->toBe("[\n1\n]");
});
