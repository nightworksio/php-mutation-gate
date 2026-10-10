<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\DigestsRecord;
use NightWorksIO\MutationGate\Core\Proof\Uncommitted;

$makeDigests = static fn(): Digests => Digests::of(Digest::sha256Of('mutation'))
    ->withSource(Path::of('src/Money.php'), Digest::sha256Of('money'))
    ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'));

it('reads a run\'s digests back as written, with the commit they were taken at or none', function () use ($makeDigests): void {
    $digests = $makeDigests();

    $taken = $digests->takenAt(Revision::ref(str_repeat('c0', 20)));

    expect(DigestsRecord::readRun(Node::config(JsonText::encode(DigestsRecord::ofRun($taken)))))->toEqual($taken)
        ->and(DigestsRecord::readRun(Node::config(JsonText::encode(DigestsRecord::ofRun($digests))))->commit())
        ->toEqual(Uncommitted::tree());
});

it('refuses a run\'s commit that is not a full commit id, rather than read it as none', function (mixed $commit) use ($makeDigests): void {
    $digests = $makeDigests();

    $written = [...DigestsRecord::ofRun($digests), 'commit' => $commit];

    expect(fn(): Digests => DigestsRecord::readRun(Node::config(JsonText::encode($written))))->toThrow(NotInShape::class);
})->with(['a ref' => 'HEAD', 'an abbreviated id' => 'c0c0c0c', 'a number' => 7]);

it('reads back the digests of a source and a test whose paths read as numbers', function (): void {
    $numeric = Digests::of(Digest::sha256Of('mutation'))
        ->withSource(Path::of('7'), Digest::sha256Of('seven'))
        ->withTest(Path::of('12'), Digest::sha256Of('twelve'));

    expect(DigestsRecord::readRun(Node::config(JsonText::encode(DigestsRecord::ofRun($numeric)))))->toEqual($numeric);
});
