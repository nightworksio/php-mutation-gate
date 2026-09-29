<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\UnitRecord;
use NightWorksIO\MutationGate\Core\Unit\Units;

$units = Units::of(
    Unit::file(Path::of('src/Money.php')),
    Unit::held(Path::of('src/Kernel.php'), Group::named('holds:kernel')),
    Unit::held(Path::of('src/Boot.php'), Filter::matching('BootTest')),
);

it('writes a unit\'s path, and the group or filter that holds it where it is held', function () use ($units): void {
    expect(UnitRecord::all($units))->toBe([
        ['path' => 'src/Money.php'],
        ['path' => 'src/Kernel.php', 'group' => 'holds:kernel'],
        ['path' => 'src/Boot.php', 'filter' => 'BootTest'],
    ])->and(UnitRecord::one(Unit::file(Path::of('src/A.php'))))->toBe(['path' => 'src/A.php']);
});

it('reads back the units it wrote', function () use ($units): void {
    expect(UnitRecord::readAll(Node::decode((string) json_encode(UnitRecord::all($units)))))->toEqual($units);
});

it('reads one unit', function (): void {
    expect(UnitRecord::read(Node::decode('{"path": "src/Kernel.php", "group": "holds:kernel"}')))
        ->toEqual(Unit::held(Path::of('src/Kernel.php'), Group::named('holds:kernel')));
});

it('refuses a unit with no path', function (): void {
    expect(static fn(): Unit => UnitRecord::read(Node::decode('{"group": "holds:kernel"}')))
        ->toThrow(NotInShape::class, 'the file.path is missing.');
});

it('refuses units that are not a list', function (): void {
    expect(static fn(): Units => UnitRecord::readAll(Node::decode('{"path": "src/A.php"}')))
        ->toThrow(NotInShape::class, 'the file is not a list.');
});
