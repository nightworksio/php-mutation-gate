<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\GateEntry;
use NightWorksIO\MutationGate\Core\Composer\Names;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/** The entry of a manifest of this text, read from modules/billing/composer.json by the package acme/billing. */
function gateEntryOf(string $json): GateEntry
{
    return GateEntry::in(Node::decode($json), Path::of('modules/billing/composer.json'), 'acme/billing');
}

it('declares the floor extra.mutation-gate.floor says', function (string $json, Floor|Exempt|Undeclared $floor): void {
    expect(gateEntryOf($json)->floor())->toEqual($floor);
})->with([
    'none' => ['{}', fn(): Undeclared => Undeclared::floor()],
    'no extra map' => ['{"extra": "none"}', fn(): Undeclared => Undeclared::floor()],
    'no mutation-gate map' => ['{"extra": {"mutation-gate": 1}}', fn(): Undeclared => Undeclared::floor()],
    'a whole number' => ['{"extra": {"mutation-gate": {"floor": 100}}}', fn(): Floor => Floor::of(100)],
    'a fraction' => ['{"extra": {"mutation-gate": {"floor": 83.41}}}', fn(): Floor => Floor::of(83.41)],
    'just above none' => ['{"extra": {"mutation-gate": {"floor": 0.01}}}', fn(): Floor => Floor::ofHundredths(1)],
    'none at all, with its reason' => [
        '{"extra": {"mutation-gate": {"floor": 0, "floorReason": "Generated"}}}',
        fn(): Exempt => Exempt::because('Generated'),
    ],
]);

it('cannot judge a floor that is no number from 0 to 100', function (string $json, string $said): void {
    expect(gateEntryOf($json)->floor())->toEqual(CannotJudge::because($said));
})->with([
    'above 100' => ['{"extra": {"mutation-gate": {"floor": 100.5}}}', 'modules/billing/composer.json: extra.mutation-gate.floor is not a number from 0 to 100.'],
    'below 0' => ['{"extra": {"mutation-gate": {"floor": -1}}}', 'modules/billing/composer.json: extra.mutation-gate.floor is not a number from 0 to 100.'],
    'text' => ['{"extra": {"mutation-gate": {"floor": "90"}}}', 'modules/billing/composer.json: extra.mutation-gate.floor is not a number from 0 to 100.'],
    'nothing' => ['{"extra": {"mutation-gate": {"floor": null}}}', 'modules/billing/composer.json: extra.mutation-gate.floor is not a number from 0 to 100.'],
    'none at all without a reason' => [
        '{"extra": {"mutation-gate": {"floor": 0}}}',
        'modules/billing/composer.json declares extra.mutation-gate.floor as 0 without a floorReason beside it.',
    ],
    'none at all with an empty reason' => [
        '{"extra": {"mutation-gate": {"floor": 0.0, "floorReason": ""}}}',
        'modules/billing/composer.json declares extra.mutation-gate.floor as 0 without a floorReason beside it.',
    ],
    'a fraction that records as none, without a reason' => [
        '{"extra": {"mutation-gate": {"floor": 0.001, "floorReason": 1}}}',
        'modules/billing/composer.json declares extra.mutation-gate.floor as 0 without a floorReason beside it.',
    ],
]);

it('declares the floor extra.mutation-gate.newCodeFloor says for new lines', function (): void {
    expect(gateEntryOf('{"extra": {"mutation-gate": {"newCodeFloor": 95}}}')->newCodeFloor())->toEqual(Floor::of(95))
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"newCodeFloor": 0}}}')->newCodeFloor())->toEqual(Floor::of(0))
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"newCodeFloor": 100}}}')->newCodeFloor())->toEqual(Floor::of(100))
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"floor": 95}}}')->newCodeFloor())->toEqual(Undeclared::floor())
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"newCodeFloor": true}}}')->newCodeFloor())
        ->toEqual(CannotJudge::because('modules/billing/composer.json: extra.mutation-gate.newCodeFloor is not a number from 0 to 100.'));
});

it('declares the floor extra.mutation-gate.securityFloor says for a package\'s security set', function (): void {
    expect(gateEntryOf('{"extra": {"mutation-gate": {"securityFloor": 97.5}}}')->securityFloor(ofAPackage: true))
        ->toEqual(Floor::of(97.5))
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"floor": 95}}}')->securityFloor(ofAPackage: true))
        ->toEqual(Undeclared::floor())
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"securityFloor": "high"}}}')->securityFloor(ofAPackage: true))
        ->toEqual(CannotJudge::because('modules/billing/composer.json: extra.mutation-gate.securityFloor is not a number from 0 to 100.'));
});

it('refuses a securityFloor in a manifest that is no package\'s, and lets one declare none', function (): void {
    expect(gateEntryOf('{"extra": {"mutation-gate": {"securityFloor": 90}}}')->securityFloor(ofAPackage: false))
        ->toEqual(CannotJudge::because(<<<'SAID'
            modules/billing/composer.json declares extra.mutation-gate.securityFloor, and it is not a package's manifest.
            A package's security set has one floor: declare it in the composer.json of the package.
            SAID))
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"floor": 90}}}')->securityFloor(ofAPackage: false))
        ->toEqual(Undeclared::floor());
});

it('names the extension classes extra.mutation-gate.extensions lists, in its order', function (): void {
    expect(gateEntryOf('{"extra": {"mutation-gate": {"extensions": ["Acme\\\\One", "Acme\\\\Two"]}}}')->extensions())
        ->toEqual(Names::of('Acme\\One', 'Acme\\Two'))
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"extensions": []}}}')->extensions())->toEqual(Names::of())
        ->and(gateEntryOf('{"extra": {"mutation-gate": {"floor": 90}}}')->extensions())->toEqual(Names::of());
});

it('cannot judge extensions that are not a list of class names, and says so as the package', function (string $extensions): void {
    expect(gateEntryOf(sprintf('{"extra": {"mutation-gate": {"extensions": %s}}}', $extensions))->extensions())
        ->toEqual(CannotJudge::because('acme/billing names extra.mutation-gate.extensions, and it is not a list of class names.'));
})->with(['"Acme\\\\One"', '["Acme\\\\One", 2]', '{"one": "Acme\\\\One"}', 'null']);

it('takes itself out of a manifest, and leaves an extra that is not a map as it was', function (): void {
    expect(GateEntry::removedFrom(Node::decode('{"extra": {"mutation-gate": {"floor": 1}, "other": {"a": 1}}, "b": [1]}')))
        ->toBe('{"extra":{"other":{"a":1}},"b":[1]}')
        ->and(GateEntry::removedFrom(Node::decode('{"extra": ["mutation-gate"]}')))->toBe('{"extra":{"0":"mutation-gate"}}')
        ->and(GateEntry::removedFrom(Node::decode('{"extra": 3}')))->toBe('{"extra":3}');
});
