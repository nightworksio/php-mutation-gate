<?php

declare(strict_types=1);

// Writes resources/report.schema.json and resources/tests.schema.json from
// the reports' own definitions, as `composer report:schema` runs it. The Unit
// suite fails while a committed file differs from what this writes.

use NightWorksIO\MutationGate\Core\Report\ReportSchema;

require dirname(__DIR__) . '/vendor/autoload.php';

file_put_contents(dirname(__DIR__) . '/resources/report.schema.json', ReportSchema::json() . "\n");
file_put_contents(dirname(__DIR__) . '/resources/tests.schema.json', ReportSchema::tests() . "\n");
