<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CollectionKiller;
use NightWorksIO\MutationGate\Core\NotGiven;

/** What Pest prints where the test with this description, in this file, was given no dataset case. */
function missingDataset(string $description, string $file): string
{
    return sprintf(
        "\n  \e[41;1m DATASET MISSING \e[49;22m The test [%s] in [%s] expects [1] argument(s) ([int \$case]), "
        . "but no dataset was provided. Please chain [with()] onto the test to supply one.\n",
        $description,
        $file,
    );
}

/** The class Pest made of this file. */
const COLLECTED_HERE = 'P\\Tests\\Unit\\Adapter\\Pest\\CollectionKillerTest';

it('names, by its id, the test whose missing dataset an own run printed, as the class Pest made of its file here', function (): void {
    $printed = missingDataset('it names, by its id, the test whose missing dataset an own run printed, as the class Pest made of its file here', __FILE__);

    expect(CollectionKiller::in($printed))->toBe(sprintf(
        '%s::__pest_evaluable_it_names__by_its_id__the_test_whose_missing_dataset_an_own_run_printed__as_the_class_Pest_made_of_its_file_here',
        COLLECTED_HERE,
    ));
});

it('names a test whose description holds [brackets], "quotes" and under_scores', function (): void {
    $printed = missingDataset('it names a test whose description holds [brackets], "quotes" and under_scores', __FILE__);

    expect(CollectionKiller::in($printed))->toBe(sprintf(
        '%s::__pest_evaluable_it_names_a_test_whose_description_holds__brackets____quotes__and_under__scores',
        COLLECTED_HERE,
    ));
});

it('names none, and so nothing the run printed, where the class Pest made of the file has no test of that description', function (): void {
    $secret = 'ghp1secretTokenFromTheEnvironment9';
    putenv(sprintf('MUTATION_GATE_TEST_SECRET=%s', $secret));

    try {
        $named = CollectionKiller::in(missingDataset((string) getenv('MUTATION_GATE_TEST_SECRET'), __FILE__));
    } finally {
        putenv('MUTATION_GATE_TEST_SECRET');
    }

    expect($named)->toBeInstanceOf(NotGiven::class);
});

it('names none where no class Pest made here is of the file the run printed', function (): void {
    expect(CollectionKiller::in(missingDataset('it is elsewhere', '/nowhere/ElsewhereTest.php')))->toBeInstanceOf(NotGiven::class);
});

it('names none where the run printed no missing dataset', function (): void {
    expect(CollectionKiller::in("FAILED  Tests\\Unit\\SomeTest > it fails\nFailed asserting that false is true.\n"))
        ->toBeInstanceOf(NotGiven::class)
        ->and(CollectionKiller::in(''))->toBeInstanceOf(NotGiven::class);
});
