<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\XmlFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads an XML file into its document', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'junit.xml', '<testsuites><testsuite name="unit"/></testsuites>');

    $read = XmlFile::read(sprintf('%s/junit.xml', $directory), CannotJudge::because('unreadable'));

    expect($read)->toBeInstanceOf(DOMDocument::class)
        ->and($read instanceof DOMDocument ? $read->documentElement?->nodeName : '')->toBe('testsuites');
});

it('answers with the refusal it is given where the file is not there or not XML', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'broken.xml', '<testsuites>');
    $refusal = CannotJudge::because('unreadable');

    expect(XmlFile::read(sprintf('%s/missing.xml', $directory), $refusal))->toBe($refusal)
        ->and(XmlFile::read(sprintf('%s/broken.xml', $directory), $refusal))->toBe($refusal);
});
