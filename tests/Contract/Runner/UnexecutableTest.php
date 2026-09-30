<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use Pest\Mutate\Mutators\Number\IncrementInteger;

// A mutant on a line php-code-coverage leaves out of its map, which Pest calls
// uncovered, judged by the tests that read the value it changes, each run
// through Pest's own override (ADR-0004, decision 8). Pest's runs are real.

it('judges each kind of value on a line that is not executable by the tests that read it', function (): void {
    $files = Paths::of(
        Path::of('src/Unexecutable/Rates.php'),
        Path::of('src/Unexecutable/BaseRate.php'),
        Path::of('src/Unexecutable/Rated.php'),
        Path::of('src/Unexecutable/Level.php'),
        Path::of('src/Unexecutable/Other.php'),
    );
    $request = MutationRequest::of($files, WholeSuite::tests())->onlyMutators(Mutators::named(IncrementInteger::class));
    $result = Library::pest(Patching::off())->runner()->mutate($request);
    $judged = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : [] as $mutant) {
        $reason = $mutant->reason();
        $judged[sprintf('%s:%d', $mutant->location()->file()->value(), $mutant->location()->start()->number())]
            = trim(sprintf('%s %s', $mutant->status()->value, $reason instanceof Reason ? $reason->text() : ''));
    }

    expect($judged)->toEqualCanonicalizing([
        'src/Unexecutable/Rates.php:14' => 'killed',
        'src/Unexecutable/Rates.php:16' => 'killed',
        'src/Unexecutable/Rates.php:18' => 'unjudged no test reaches this value',
        'src/Unexecutable/Rates.php:20' => 'killed',
        'src/Unexecutable/Rates.php:22' => 'killed',
        'src/Unexecutable/BaseRate.php:9' => 'killed',
        'src/Unexecutable/Rated.php:9' => 'killed',
        'src/Unexecutable/Level.php:10' => 'killed',
        'src/Unexecutable/Level.php:11' => 'killed',
        'src/Unexecutable/Other.php:10' => 'killed',
    ]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');
