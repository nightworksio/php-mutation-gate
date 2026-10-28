<?php

declare(strict_types=1);

// Writes resources/report.schema.json, resources/tests.schema.json,
// resources/webhook.schema.json, resources/explain.schema.json and
// resources/summary.schema.json from the reports' own definitions, and
// resources/doctor.schema.json from doctor's, as `composer report:schema`
// runs it. The Unit suite fails while a committed file differs from what this
// writes.

use NightWorksIO\MutationGate\Core\Doctor\DoctorSchema;
use NightWorksIO\MutationGate\Core\Report\ReportSchema;
use NightWorksIO\MutationGate\Core\Report\SummarySchema;

require __DIR__ . '/../vendor/autoload.php';

file_put_contents(__DIR__ . '/../resources/report.schema.json', ReportSchema::json() . "\n");
file_put_contents(__DIR__ . '/../resources/tests.schema.json', ReportSchema::tests() . "\n");
file_put_contents(__DIR__ . '/../resources/doctor.schema.json', DoctorSchema::json() . "\n");
file_put_contents(__DIR__ . '/../resources/webhook.schema.json', ReportSchema::webhook() . "\n");
file_put_contents(__DIR__ . '/../resources/explain.schema.json', ReportSchema::explain() . "\n");
file_put_contents(__DIR__ . '/../resources/summary.schema.json', SummarySchema::json() . "\n");
