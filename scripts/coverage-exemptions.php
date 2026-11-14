<?php

declare(strict_types=1);

// The files of src G7 leaves off its floor, each with why no covered run can
// measure it.

return [
    'src/Adapter/Pest/Split/PcovLines.php' => "pcov's own calls: clearing and stopping pcov wipes the collection that would measure them, and PcovLinesTest holds the file to calling pcov alone.",
];
