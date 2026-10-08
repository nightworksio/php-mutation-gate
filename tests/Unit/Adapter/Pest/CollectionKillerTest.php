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
    expect(CollectionKiller::in(missingDataset('it is collected', __FILE__)))
        ->toBe(sprintf('%s::__pest_evaluable_it_is_collected', COLLECTED_HERE));
});

it('names the test whatever its description holds that Pest writes otherwise in a method name', function (): void {
    $description = 'it reads [a] "quoted" name_with: punctuation';

    expect(CollectionKiller::in(missingDataset($description, __FILE__)))
        ->toBe(sprintf('%s::__pest_evaluable_it_reads__a___quoted__name__with__punctuation', COLLECTED_HERE));
});

it('names none where no class Pest made here is of the file the run printed', function (): void {
    expect(CollectionKiller::in(missingDataset('it is elsewhere', '/nowhere/ElsewhereTest.php')))->toBeInstanceOf(NotGiven::class);
});

it('names none where the run printed no missing dataset', function (): void {
    expect(CollectionKiller::in("FAILED  Tests\\Unit\\SomeTest > it fails\nFailed asserting that false is true.\n"))
        ->toBeInstanceOf(NotGiven::class)
        ->and(CollectionKiller::in(''))->toBeInstanceOf(NotGiven::class);
});
