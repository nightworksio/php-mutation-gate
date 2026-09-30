<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\KeysRecord;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Tests\Support\Stopwatch;

$keys = Keys::none()
    ->with(Path::of('src/A.php'), Digest::of('9c1e'))
    ->with(Path::of('src/B.php'), Unkeyed::because('There is no coverage map.'));

it('writes each unit\'s key, and why a unit has none', function () use ($keys): void {
    expect(Json::encode(['keys' => KeysRecord::of($keys)]))->toBe(<<<'JSON'
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
    expect(Json::encode(['keys' => KeysRecord::of(Keys::none())]))->toBe("{\n    \"keys\": {}\n}");
});

it('reads back the keys it wrote', function () use ($keys): void {
    $read = KeysRecord::read(Node::decode(Json::encode(['keys' => KeysRecord::of($keys)]))->field('keys'));

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

it('reads and writes thousands of keys in linear time', function (): void {
    $written = [];

    foreach (range(1, 5000) as $at) {
        $written[sprintf('src/F%d.php', $at)] = hash('sha256', sprintf('%d', $at));
    }

    $read = Keys::none();
    $seconds = Stopwatch::seconds(static function () use ($written, &$read): void {
        $read = KeysRecord::read(Node::decode(Json::encode($written)));
        KeysRecord::of($read);
    });

    expect($read)->toHaveCount(5000)
        ->and($read->keyOf(Path::of('src/F5000.php')))->toEqual(Digest::of(hash('sha256', '5000')))
        ->and($seconds)->toBeLessThan(Stopwatch::BOUND);
});
