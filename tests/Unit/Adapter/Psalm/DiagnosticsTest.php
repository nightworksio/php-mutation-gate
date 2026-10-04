<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Psalm\Diagnostics;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Node;

it('spells a file\'s URI with each segment encoded, as the server decodes it', function (): void {
    expect(Diagnostics::uriOf('/work/my app/src/A+b%.php'))->toBe('file:///work/my%20app/src/A%2Bb%25.php');
});

it('reads each diagnostic as a finding by its type, its message without the type before it, an error by its severity', function (): void {
    $published = Node::decode(sprintf('{"uri": "%s", "version": 2, "diagnostics": [
        {"severity": 1, "message": "[InvalidReturnType] Wrong.", "data": {"type": "InvalidReturnType"}},
        {"severity": 2, "message": "[MixedReturn] Mixed.", "data": {"type": "MixedReturn"}},
        {"severity": 3, "message": "Untyped, said plainly.", "data": {"type": "MissingParamType"}}
    ]}', Diagnostics::uriOf('/work/my app/src/Money.php')));

    expect(Diagnostics::of($published, FindingFiles::under(Root::of('/work/my app'))))->toEqual(Findings::of(
        Finding::error(Path::of('src/Money.php'), 'InvalidReturnType', 'Wrong.'),
        Finding::lesser(Path::of('src/Money.php'), 'MixedReturn', 'Mixed.'),
        Finding::lesser(Path::of('src/Money.php'), 'MissingParamType', 'Untyped, said plainly.'),
    ));
});

it('places a finding in the file a check substitutes, and reads a URI that is no file\'s as a path', function (): void {
    $files = FindingFiles::under(Root::of('/work'))->substituting(MutantCheck::of(Path::of('src/Money.php'), Path::of('mutants/Money.php')));
    $diagnostic = '{"severity": 1, "message": "[A] b", "data": {"type": "A"}}';

    expect(Diagnostics::of(Node::decode(sprintf('{"uri": "file:///work/mutants/Money.php", "diagnostics": [%s]}', $diagnostic)), $files))
        ->toEqual(Findings::of(Finding::error(Path::of('src/Money.php'), 'A', 'b')))
        ->and(Diagnostics::of(Node::decode(sprintf('{"uri": "untitled:1", "diagnostics": [%s]}', $diagnostic)), $files))
        ->toEqual(Findings::of(Finding::error(Path::of('untitled:1'), 'A', 'b')));
});
