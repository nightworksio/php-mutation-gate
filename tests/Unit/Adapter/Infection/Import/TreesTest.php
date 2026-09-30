<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\Trees;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Tests\Support\Imports;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$project = static function (): Project {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Legacy/Old.php', '<?php');
    Scratch::write($root, 'src/Kernel.php', '<?php');
    Scratch::write($root, 'lib/Legacy/Old.php', '<?php');

    return Project::at(Root::of($root), Paths::none(), Path::of('.gate'));
};

it('declares a tree per directory, each excluding what source.excludes names inside it, at the floor', function () use ($project): void {
    $settings = Node::decode('{"source": {"directories": ["src", "lib", "app"], "excludes": ["Legacy", "Kernel.php", "Nope", "/\\\\.interface\\\\.php/", "{Tests}"]}, "minMsi": 80}');
    $import = Trees::of($settings, $project(), new Absent());

    expect(Imports::written($import))->toBe(
        '{"trees":[{"path":"src","floor":80,"exclude":["src/Legacy/**","src/Kernel.php"]},'
        . '{"path":"lib","floor":80,"exclude":["lib/Legacy/**"]},{"path":"app","floor":80}]}',
    )->and(Imports::keys($import))->toBe([
        '  source.directories: imported as trees: src, lib, app, which replace the list the tree source finds',
        '  source.excludes: imported as src: the exclude src/Legacy/**; lib: the exclude lib/Legacy/**',
        '  source.excludes: imported as src: the exclude src/Kernel.php',
        '  source.excludes: dropped, because Nope names nothing in any tree',
        '  source.excludes: dropped, because /\\.interface\\.php/ is a regular expression, which no glob of the gate\'s can say',
        '  source.excludes: dropped, because {Tests} is a regular expression, which no glob of the gate\'s can say',
    ]);
});

it('gives the trees zero-config found the floor and the excludes, keeping one it found exempt', function () use ($project): void {
    $found = Listed::of(
        DeclaredTree::of(Path::of('src'), Undeclared::floor(), Listed::of()),
        DeclaredTree::of(Path::of('src/Generated'), Exempt::because('phpunit.xml excludes it'), Listed::of()),
    );

    expect(Imports::written(Trees::of(Node::decode('{"minMsi": 70}'), $project(), $found)))->toBe(
        '{"trees":[{"path":"src","floor":70},{"path":"src/Generated","floor":0,"reason":"phpunit.xml excludes it"}]}',
    )->and(Imports::written(Trees::of(Node::decode('{"source": {"excludes": ["Legacy"]}}'), $project(), $found)))->toBe(
        '{"trees":[{"path":"src","exclude":["src/Legacy/**"]},{"path":"src/Generated","floor":0,"reason":"phpunit.xml excludes it"}]}',
    );
});

it('declares no trees where the config names no directory, floor or exclude', function () use ($project): void {
    $found = Listed::of(DeclaredTree::of(Path::of('src'), Undeclared::floor(), Listed::of()));

    expect(Imports::written(Trees::of(Node::decode('{"timeout": 10}'), $project(), $found)))->toBe('{}')
        ->and(Imports::written(Trees::of(Node::decode('{"source": {"directories": [1, ""]}}'), $project(), new Absent())))->toBe('{}');
});
