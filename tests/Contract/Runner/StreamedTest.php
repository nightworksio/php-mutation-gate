<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\MultiplicationToDivision;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Arithmetic\PostIncrementToPostDecrement;

$holds = [
    'holds:src/Adapter/Pest',
];

it('kills in its stream a mutant a test tells apart, stops at its limit one that never ends, and runs on its own one only a test holding a socket reaches', function (): void {
    $source = Tree::at(sprintf('%s/src/Streamed.php', Library::DIRECTORY));
    $test = Tree::at(sprintf('%s/tests/StreamedSpec.php', Library::DIRECTORY));
    copy(Tree::at('tests/Contract/Runner/streamed/src/Streamed.php'), $source);
    copy(Tree::at('tests/Contract/Runner/streamed/tests/StreamedSpec.php'), $test);
    Patch::applyIn(Library::vendor(), new PackageDirectory());

    try {
        $result = Library::pest(Patching::on(Library::canary())->splitting(
            StreamSettings::of(enabled: true, children: 1, changeCheck: false, killersFirst: false),
        ))->runner()->mutate(
            MutationRequest::of(Paths::of(Path::of('src/Streamed.php')), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of('src/Streamed.php')),
                Narrowing::none()->toMutators(Mutators::named(
                    PlusToMinus::class,
                    PostIncrementToPostDecrement::class,
                    MultiplicationToDivision::class,
                )),
            ),
        );
    } finally {
        unlink($source);
        unlink($test);
    }

    $judged = array_map(static fn(Mutant $mutant): array => [
        $mutant->location()->start()->number(),
        $mutant->status(),
        $mutant->status() === MutantStatus::Killed
            ? array_map(static fn(TestId $killer): string => $killer->value(), [...$mutant->killers()])
            : [],
        $result instanceof MutationResult && $result->evidence()->of($mutant->id())->isStreamed(),
    ], $result instanceof MutationResult ? [...$result->mutants()] : []);
    usort($judged, static fn(array $one, array $other): int => $one[0] <=> $other[0]);

    expect($judged)->toBe([
        [16, MutantStatus::Killed, ['P\\Tests\\StreamedSpec::__pest_evaluable_it_adds'], true],
        [23, MutantStatus::TimedOut, [], true],
        [32, MutantStatus::Killed, ['P\\Tests\\StreamedSpec::__pest_evaluable_it_doubles_while_it_holds_a_socket'], false],
    ]);
})->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);
