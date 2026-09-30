<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\KeysRecord;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Tests\Support\Growth;

$keys = Keys::none()
    ->with(Path::of('src/A.php'), Digest::of('9c1e'))
    ->with(Path::of('src/B.php'), Unkeyed::because('There is no coverage map.'));

it('writes each unit\'s key, and why a unit has none', function () use ($keys): void {
    expect(JsonText::encode(['keys' => KeysRecord::of($keys)]))->toBe(<<<'JSON'
        {
            "keys": {
                "src/A.php": "9c1e",
                "src/B.php": {
                    "unkeyed": "There is no coverage map."
                }
            }
        }
        JSON);
});

it('writes no keys as an empty map', function (): void {
    expect(JsonText::encode(['keys' => KeysRecord::of(Keys::none())]))->toBe("{\n    \"keys\": {}\n}");
});

it('reads back the keys it wrote', function () use ($keys): void {
    $read = KeysRecord::read(Node::decode(JsonText::encode(['keys' => KeysRecord::of($keys)]))->field('keys'));

    expect($read)->toEqual($keys);
});

it('refuses keys that are not a map of text', function (): void {
    expect(fn(): Keys => KeysRecord::read(Node::decode('{"keys": {"src/A.php": 7}}')->field('keys')))
        ->toThrow(NotInShape::at('the file.keys.src/A.php', 'text'))
        ->and(fn(): Keys => KeysRecord::read(Node::decode('{"keys": {"src/A.php": {"unkeyed": 7}}}')->field('keys')))
        ->toThrow(NotInShape::at('the file.keys.src/A.php.unkeyed', 'text'))
        ->and(fn(): Keys => KeysRecord::read(Node::decode('{}')->field('keys')))
        ->toThrow(NotInShape::missing('the file.keys'));
});

it('reads and writes keys in time linear in their number', function (): void {
    $read = static function (int $size): Closure {
        $written = [];

        foreach (range(1, $size) as $at) {
            $written[sprintf('src/F%d.php', $at)] = hash('sha256', sprintf('%d', $at));
        }

        $json = JsonText::encode($written);

        return static function () use ($json): Keys {
            $read = KeysRecord::read(Node::decode($json));
            KeysRecord::of($read);

            return $read;
        };
    };

    expect($read(10)())->toHaveCount(10)
        ->and($read(10)()->keyOf(Path::of('src/F10.php')))->toEqual(Digest::of(hash('sha256', '10')))
        ->and(Growth::of(625, $read))->toBeLessThan(Growth::LINEAR);
});

it('reads back the key of a unit whose path reads as a number', function (): void {
    $numeric = Keys::none()->with(Path::of('7'), Digest::of('9c1e'));

    expect(KeysRecord::read(Node::decode(JsonText::encode(['keys' => KeysRecord::of($numeric)]))->field('keys')))
        ->toEqual($numeric);
});
