<?php

declare(strict_types=1);

// Writes resources/report.schema.json from the report's own definitions, as
// `composer report:schema` runs it. The Unit suite fails while the committed
// file differs from what this writes.

use NightWorksIO\MutationGate\Core\Report\ReportSchema;

require dirname(__DIR__) . '/vendor/autoload.php';

file_put_contents(dirname(__DIR__) . '/resources/report.schema.json', ReportSchema::json() . "\n");
