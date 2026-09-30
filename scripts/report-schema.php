<?php

declare(strict_types=1);

// Writes resources/report.schema.json and resources/tests.schema.json from
// the reports' own definitions, and resources/doctor.schema.json from
// doctor's, as `composer report:schema` runs it. The Unit suite fails while a
// committed file differs from what this writes.

use NightWorksIO\MutationGate\Core\Doctor\DoctorSchema;
use NightWorksIO\MutationGate\Core\Report\ReportSchema;

require dirname(__DIR__) . '/vendor/autoload.php';

file_put_contents(dirname(__DIR__) . '/resources/report.schema.json', ReportSchema::json() . "\n");
file_put_contents(dirname(__DIR__) . '/resources/tests.schema.json', ReportSchema::tests() . "\n");
file_put_contents(dirname(__DIR__) . '/resources/doctor.schema.json', DoctorSchema::json() . "\n");
