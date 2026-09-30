<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

/**
 * The names a runner gives its tests, as the plan file writes them: by each
 * coverage id, the test's file and description, and the data set row where
 * the id is one.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-type Written array{file: string, description: string, row?: string}
 */
final readonly class TestNamesRecord
{
    private const string FILE = 'file';

    private const string DESCRIPTION = 'description';

    private const string ROW = 'row';

    /** @return array<string, Written> each name, by the id it names */
    public static function of(TestNames $names): array
    {
        $written = [];

        foreach ($names as $test => $name) {
            $whole = $name instanceof TestRow ? $name->test() : $name;
            $written[$test->value()] = [
                self::FILE => $whole->file()->value(),
                self::DESCRIPTION => $whole->description(),
                ...$name instanceof TestRow ? [self::ROW => $name->row()] : [],
            ];
        }

        return $written;
    }

    /** @throws NotInShape */
    public static function read(Node $names): TestNames
    {
        $read = TestNames::none();

        foreach ($names->entries() as $id => $name) {
            $whole = TestName::in(Path::of($name->field(self::FILE)->text()), $name->field(self::DESCRIPTION)->text());
            $row = $name->field(self::ROW);
            $read = $read->with(
                TestId::of(sprintf('%s', $id)),
                $row->isPresent() ? TestRow::of($whole, $row->text()) : $whole,
            );
        }

        return $read;
    }
}
