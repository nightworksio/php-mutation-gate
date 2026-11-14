<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\MapFileRead;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Coverage maps' texts an engine may vouch for, each holding nothing the
 * careful reading drops, and the map the careful reading builds of a text,
 * written, so two readings can be compared (ADR-0118).
 */
final readonly class VouchedMaps
{
    /** @return array<string, array{string}> each text, by what it holds */
    public static function texts(): array
    {
        return [
            'timed and untimed tests, covered and missed lines and methods' => [
                '{"format":1,"commit":"abc","dirty":false,"tests":[{"id":"A::a","seconds":0.25},{"id":"A::b"},'
                . '{"id":"B::c","seconds":1}],"files":{"src/A.php":{"3":[0,1],"4":[]},"src/B.php":{"1":[2,2]}},'
                . '"methods":{"src/A.php":[{"name":"m","start":3,"end":4}],"src/B.php":[]},"keys":{"tests/A.php":"k"}}',
            ],
            'two paths that read as one' => [
                '{"format":1,"tests":[{"id":"A::a"},{"id":"A::b"}],'
                . '"files":{"./src/A.php":{"1":[0]},"src/A.php":{"1":[1],"2":[]}}}',
            ],
            'a path that reads as a number' => [
                '{"format":1,"tests":[{"id":"7"}],"files":{"123":{"1":[0]}},"methods":{"123":[{"name":"f","start":1,"end":1}]}}',
            ],
            'nothing' => ['{"format":1,"tests":[],"files":{}}'],
            'its files before its tests' => ['{"format":1,"files":{"src/A.php":{"9":[0]}},"tests":[{"id":"A::a","seconds":0}]}'],
            'what each process\'s tests loaded' => [
                '{"format":1,"tests":[{"id":"A::a"},{"id":"A::b"}],"files":{"src/A.php":{"1":[0]}},'
                . '"loads":[[[0,["vendor/x","vendor/y"],true],[1,[],false]],[],[[1,["vendor/x"],false]]]}',
            ],
            'no process recorded' => ['{"format":1,"tests":[{"id":"A::a"}],"files":{},"loads":[]}'],
        ];
    }

    /** @return array<string, array{string}> texts holding an entry the careful reading drops, by what it is */
    public static function dropping(): array
    {
        return [
            'a test listed twice' => ['{"format":1,"tests":[{"id":"A::a"},{"id":"A::a"}],"files":{"a":{"1":[1]}}}'],
            'a place past the last test' => ['{"format":1,"tests":[{"id":"A::a"}],"files":{"a":{"1":[1]}}}'],
            'a line of nought' => ['{"format":1,"tests":[],"files":{"a":{"0":[]}}}'],
            'a test with no id' => ['{"format":1,"tests":[{"seconds":1}],"files":{}}'],
            'seconds below none' => ['{"format":1,"tests":[{"id":"A::a","seconds":-1}],"files":{}}'],
            'a method ending before it starts' => ['{"format":1,"tests":[],"files":{},"methods":{"a":[{"name":"m","start":2,"end":1}]}}'],
            'a load past the last test' => ['{"format":1,"tests":[{"id":"A::a"}],"files":{},"loads":[[[1,[],true]]]}'],
            'a load without whether it started processes' => ['{"format":1,"tests":[{"id":"A::a"}],"files":{},"loads":[[[0,["p"]]]]}'],
            'a package that is no text' => ['{"format":1,"tests":[{"id":"A::a"}],"files":{},"loads":[[[0,[7],false]]]}'],
            'another format' => ['{"format":2,"tests":[],"files":{}}'],
            'no JSON' => ['{"format":1,'],
        ];
    }

    /** The map the careful reading builds of a text, written as its file writes it. */
    public static function carefully(string $json): string
    {
        return self::written(MapFileRead::read(Node::decode($json)));
    }

    /** A map, written as its file writes it, with neither where it was measured nor its keys. */
    public static function written(CoverageMap $map): string
    {
        return MapText::of($map)->written(Unplaced::map(), NotGiven::value());
    }
}
