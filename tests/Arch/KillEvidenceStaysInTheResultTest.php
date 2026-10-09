<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Layer;
use NightWorksIO\MutationGate\Tests\Support\Source;

// A kill's evidence holds the end of what the project's own code printed
// (ADR-0014, decision 16), which is text an attacker can write: markdown,
// mentions, HTML and workflow commands. It is carried from the runners to the
// shard result and written there as JSON. It reaches a report only as the
// reason of a kill left unjudged for want of evidence (ADR-0014, decision 17,
// see Unevidenced), which every renderer keeps as text, as it keeps any other
// reason: MarkdownTest and ConsoleReportTest hold that with a hostile tail. A
// renderer that takes the evidence on keeps the project's text as text first,
// as every other renderer of project output does, and then joins this list.

/** The files that may name a kill's evidence or read it from a result: its makers, its carriers and its writer. */
const EVIDENCE_HOLDERS = [
    'src/Adapter/Infection/KillOutput.php',
    'src/Adapter/Infection/Results.php',
    'src/Adapter/Pest/Ending.php',
    'src/Adapter/Pest/FoundAgain.php',
    'src/Adapter/Pest/Interpretation.php',
    'src/Adapter/Pest/MutationRun.php',
    'src/Adapter/Pest/OwnRun.php',
    'src/Adapter/Pest/OwnRuns.php',
    'src/Adapter/Pest/Recording/RecordLine.php',
    'src/Adapter/Pest/Unexecutable/Judging.php',
    'src/Adapter/Pest/Unexecutable/Outcome.php',
    'src/Adapter/Pest/Unexecutable/Trial.php',
    'src/Adapter/PhpUnit/Judged.php',
    'src/Adapter/PhpUnit/MutantRun.php',
    'src/Adapter/PhpUnit/MutationRun.php',
    'src/Adapter/PhpUnit/PreparedBatch.php',
    'src/Adapter/PhpUnit/Twins.php',
    'src/Adapter/PhpUnit/Warm/Forked.php',
    'src/Adapter/PhpUnit/Warm/Workforce.php',
    'src/Cli/Flow/Hidden.php',
    'src/Cli/Flow/Invoking.php',
    'src/Cli/Flow/Spent.php',
    'src/Core/Mutant/Ended.php',
    'src/Core/Mutant/Evidence.php',
    'src/Core/Mutant/EvidenceRecord.php',
    'src/Core/Mutant/Evidences.php',
    'src/Core/Mutant/Unevidenced.php',
    'src/Core/Plan/ShardResultFile.php',
    'src/Core/Runner/MutationResult.php',
];

it('keeps a kill\'s evidence, which holds what project code printed, out of every report, comment and log', function (): void {
    $types = array_map(
        static fn(string $type): string => sprintf('%s\\Core\\Mutant\\%s', Layer::ROOT, $type),
        ['Ended', 'Evidence', 'Evidences', 'EvidenceRecord'],
    );
    $holders = [];

    foreach (Source::under('src') as $source) {
        $names = array_intersect($source->names(), $types) !== [];
        $reads = str_contains((string) file_get_contents($source->path), '->evidence()');
        $declares = in_array(Source::classAtPath($source->path), $types, strict: true);

        if ($names || $reads || $declares) {
            $holders[] = $source->path;
        }
    }

    sort($holders);
    $added = array_values(array_diff($holders, EVIDENCE_HOLDERS));

    expect($added)->toBe([], sprintf(
        "These name a kill's evidence or read it from a result:\n  %s\n\nIts tail is what the project's code printed, so an attacker writes it. Render it only as text, never as markdown, a mention, HTML or a workflow command, test that with a hostile tail, and then add the file here (ADR-0014, decision 16).",
        implode("\n  ", $added),
    ))->and(array_values(array_diff(EVIDENCE_HOLDERS, $holders)))->toBe([]);
});
