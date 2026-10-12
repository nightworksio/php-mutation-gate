<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use NightWorksIO\MutationGate\Tests\Support\Gates;
use NightWorksIO\MutationGate\Tests\Support\Rules;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

// V1–V4: the bot explains every CI job from tables in main, never in words of
// its own (ADR-0019, decisions 5 and 7). So the tables are held to the
// workflows, to the tools' recorded output, and to the tools themselves.

/** The rule tables of .github/bot/rules, by the tool each explains. */
const RULE_TABLES = ['phpstan', 'pint', 'rector', 'markdownlint'];

/** The tools whose findings can name one of this package's own rules: its analyser and its Arch suite. */
const TOOLS_NAMING_RULES = ['phpstan', 'pest'];

/**
 * The rules a tool's table explains.
 *
 * @return list<string>
 */
function explainedBy(string $tool): array
{
    $table = json_decode((string) file_get_contents(Tree::at(sprintf('.github/bot/rules/%s.json', $tool))), associative: true);
    $rules = is_array($table) && array_key_exists('rules', $table) && is_array($table['rules']) ? $table['rules'] : [];

    return array_map(strval(...), array_keys($rules));
}

/**
 * Every rule a tool's findings can name that main explains: its table, the
 * package's own rules, or the rules of the gates its evidence comes from.
 *
 * @return list<string>
 */
function vocabularyOf(string $tool): array
{
    $words = in_array($tool, RULE_TABLES, strict: true) ? explainedBy($tool) : [];
    $words = in_array($tool, TOOLS_NAMING_RULES, strict: true) ? [...$words, ...array_keys(Rules::documented())] : $words;

    foreach (Gates::entries() as $entry) {
        foreach ($entry['evidence'] as $producer) {
            $words = $producer['tool'] === $tool ? [...$words, ...array_keys($entry['rules'])] : $words;
        }
    }

    return $words;
}

/**
 * The rules one of the recorded fixtures names, as evidence.py reads it.
 *
 * @return list<string>
 */
function rulesIn(string $fixture): array
{
    $tool = pathinfo($fixture, PATHINFO_FILENAME);
    $process = new Process(['python3', '.github/scripts/evidence.py', '--read', $tool, $fixture], Tree::root());
    $process->mustRun();
    $findings = json_decode($process->getOutput(), associative: true);
    $rules = [];

    foreach (is_array($findings) ? $findings : [] as $finding) {
        $rules[] = is_array($finding) && is_string($finding['rule']) ? $finding['rule'] : '';
    }

    return $rules;
}

/**
 * Whether a job's last step explains its gate when the job fails.
 *
 * @param list<array{id: string, if: string, run: string, uses: string, with: array<string, string>}> $steps
 */
function explainsItsFailure(string $gate, array $steps): bool
{
    $last = end($steps);

    return is_array($last)
        && $last['if'] === 'failure()'
        && $last['run'] === sprintf('python3 .github/scripts/gate_summary.py %s', $gate);
}

/**
 * What was found wrong, the empty findings left out, each once.
 *
 * @param  list<string> $found
 * @return list<string>
 */
function stated(array $found): array
{
    return array_values(array_unique(array_filter($found, static fn(string $one): bool => $one !== '')));
}

it('has an entry in gates.json for every CI job, and a job for every entry', function (): void {
    $jobs = Gates::jobs();
    $entries = Gates::entries();
    $wrong = [];

    foreach (array_keys(array_diff_key($jobs, $entries)) as $gate) {
        $wrong[] = sprintf('%s has no entry', $gate);
    }

    foreach (array_keys(array_diff_key($entries, $jobs)) as $gate) {
        $wrong[] = sprintf('%s is no job', $gate);
    }

    foreach (array_intersect_key($entries, $jobs) as $gate => $entry) {
        $check = $jobs[$gate]['check'];
        $wrong[] = match (true) {
            $entry['check'] !== $check => sprintf('%s is checked as "%s", and its entry says "%s"', $gate, $check, $entry['check']),
            $entry['checks'] === '' || $entry['reproduce'] === '' => sprintf('%s does not say what it checks and how to reproduce it', $gate),
            default => '',
        };
    }

    // V1
    expect(stated($wrong))->toBe([], sprintf(
        "gates.json and the workflows disagree:\n  %s\n\nEvery CI job has an entry saying what it checks and how to reproduce it (V1).",
        implode("\n  ", stated($wrong)),
    ));
});

it('leaves evidence from every CI job, and explains every failure', function (): void {
    $jobs = Gates::jobs();
    $wrong = [];

    foreach (Gates::entries() as $gate => $entry) {
        $steps = array_key_exists($gate, $jobs) ? $jobs[$gate]['steps'] : [];
        $shipped = array_key_exists($gate, $jobs) && $jobs[$gate]['shipped'];
        $ids = [...array_column($steps, 'id'), ...array_key_exists($gate, $jobs) ? $jobs[$gate]['legs'] : []];
        $runs = array_column($steps, 'run');
        $uploads = array_column(array_column($steps, 'with'), 'name');

        foreach ($entry['evidence'] as $producer) {
            $wrong[] = in_array($producer['step'], $ids, strict: true) ? '' : sprintf('%s reads a step or leg %s it does not have', $gate, $producer['step']);
        }

        $wrong[] = match (true) {
            $entry['none'] && $entry['why'] === '' => sprintf('%s leaves no evidence and does not say why', $gate),
            $shipped && ! $entry['none'] => sprintf('%s is a job of the workflow this package ships, which leaves no evidence of this repository\'s: its entry says none, and why', $gate),
            $shipped => '',
            ! $entry['none'] && $entry['evidence'] === [] => sprintf('%s names no step whose evidence it reads', $gate),
            ! $entry['none'] && ! in_array(sprintf('python3 .github/scripts/evidence.py %s', $gate), $runs, strict: true) => sprintf('%s writes no evidence', $gate),
            ! $entry['none'] && ! in_array(sprintf('evidence-%s', Gates::fileName($gate)), $uploads, strict: true) => sprintf('%s uploads no evidence', $gate),
            ! explainsItsFailure($gate, $steps) => sprintf('%s does not end by explaining its failure', $gate),
            default => '',
        };
    }

    foreach (Gates::ungathered() as $workflow => $waiting) {
        $wrong[] = $waiting === [] ? '' : sprintf('the job gathering the evidence of %s does not wait for %s', $workflow, implode(', ', $waiting));
    }

    // V2
    expect(stated($wrong))->toBe([], sprintf(
        "These jobs leave the bot nothing to read:\n  %s\n\nEvery CI job writes and uploads its evidence and ends with gate_summary.py, or its entry says why it has none (V2).",
        implode("\n  ", stated($wrong)),
    ));
});

it('explains every rule the recorded evidence names, and every step that can fail with none', function (): void {
    $unexplained = [];

    foreach (Tree::filesUnder('tests/Fixtures/Evidence', '') as $fixture) {
        $tool = pathinfo($fixture, PATHINFO_FILENAME);

        foreach (array_diff(rulesIn($fixture), vocabularyOf($tool)) as $rule) {
            $unexplained[] = sprintf('%s: %s', $tool, $rule);
        }
    }

    foreach (Gates::entries() as $gate => $entry) {
        foreach ($entry['evidence'] as $producer) {
            $unexplained[] = array_key_exists($producer['tool'], $entry['rules']) ? '' : sprintf('%s: %s failing with no finding', $gate, $producer['tool']);
        }

        $slug = $entry['troubleshooting'];
        $sections = (string) file_get_contents(Tree::at('.docs/guide/troubleshooting.md'));
        $unexplained[] = $slug === '' || str_contains($sections, sprintf("\n## %s\n", $slug)) ? '' : sprintf('%s: no section %s', $gate, $slug);
    }

    // V3
    expect(stated($unexplained))->toBe([], sprintf(
        "Nothing in main explains these:\n  %s\n\nEvery rule a tool reports has its line in .github/bot/rules, ARCHITECTURE.md or its gate's rules in .github/gates.json (V3).",
        implode("\n  ", stated($unexplained)),
    ));
});

/** Whether a generated table was written from the release of its tool that is installed. */
function writtenForTheInstalled(string $tool, string $package): bool
{
    $written = json_decode((string) file_get_contents(Tree::at(sprintf('.github/bot/rules/%s.json', $tool))), associative: true);

    return is_array($written) && $written['version'] === InstalledVersions::getPrettyVersion($package);
}

it('holds the generated rule tables to what the locked tools say of their rules', function (string $tool): void {
    $process = new Process(['php', sprintf('scripts/bot-rules/%s.php', $tool)], Tree::root(), timeout: 300);
    $process->mustRun();

    // V4
    expect($process->getOutput())->toBe((string) file_get_contents(Tree::at(sprintf('.github/bot/rules/%s.json', $tool))), sprintf(
        '.github/bot/rules/%s.json is not what the locked tool says. Run composer bot:rules and commit it (V4).',
        $tool,
    ));
})->with(['pint', 'rector'])->skip(
    fn(): bool => ! writtenForTheInstalled('pint', 'laravel/pint') || ! writtenForTheInstalled('rector', 'rector/rector'),
    'The lowest dependencies install other releases than the tables were written from.',
);
