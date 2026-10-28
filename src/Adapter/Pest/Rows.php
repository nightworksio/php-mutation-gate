<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** Which rows of a data set a Pest filter built from a test's id selects. */
enum Rows
{
    /** Every row of the test, as pest-plugin-mutate's filter selects them. */
    case Every;

    /** Only the row the id names, and every row where it names none. */
    case Named;
}
