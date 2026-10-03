<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Watch\Watched;

/** The files `src` and `tests` hold, before any is seen. */
function watchedOver(): Watched
{
    return Watched::in(
        Trees::of(Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root()))),
        SuiteDirectory::of(Path::of('tests'), 'Test.php'),
    );
}

/** @param array<string, string> $files what each file holds, by its path */
function fingerprintsOf(array $files): Fingerprints
{
    $fingerprints = Fingerprints::none();

    foreach ($files as $path => $text) {
        $fingerprints = $fingerprints->with(Fingerprint::of(Path::of($path), Digest::sha256Of($text)));
    }

    return $fingerprints;
}

it('sees a change to a file of a tree or of the tests, whether it is new, gone or holds something else', function (): void {
    $then = watchedOver()->at(fingerprintsOf([
        'src/A.php' => 'a',
        'src/Gone.php' => 'g',
        'tests/ATest.php' => 't',
        'tests/Support/Fake.php' => 'f',
    ]));
    $now = watchedOver()->at(fingerprintsOf([
        'src/A.php' => 'a2',
        'src/New.php' => 'n',
        'tests/ATest.php' => 't',
        'tests/Support/Fake.php' => 'f2',
    ]));

    expect($now->changesSince($then))->toEqual(Changes::of(
        Change::modified(Path::of('src/A.php'), Lines::none()),
        Change::added(Path::of('src/New.php'), Lines::none()),
        Change::modified(Path::of('tests/Support/Fake.php'), Lines::none()),
        Change::deleted(Path::of('src/Gone.php')),
    ));
});

it('sees no change in a file written again as it was, or in one outside the trees and the tests', function (): void {
    $then = watchedOver()->at(fingerprintsOf(['src/A.php' => 'a', 'README.md' => 'r', 'tests/ATest.php' => 't']));
    $now = watchedOver()->at(fingerprintsOf(['src/A.php' => 'a', 'README.md' => 'r2', 'composer.json' => '{}', 'tests/ATest.php' => 't']));

    expect(count($now->changesSince($then)))->toBe(0);
});
