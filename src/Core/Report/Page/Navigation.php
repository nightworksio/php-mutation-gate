<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function in_array;
use function json_encode;

use const JSON_HEX_AMP;
use const JSON_HEX_TAG;

use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\MutantJson;
use NightWorksIO\MutationGate\Core\Report\Outcome;
use NightWorksIO\MutationGate\Core\Report\Term;

use function sodium_bin2base64;
use function sprintf;

/**
 * What the HTML report's navigation reads, in two parts, so a page of many
 * mutants is ready as soon as its list of files is.
 *
 * The index is JSON in a script element of its own, every `<`, `>` and `&`
 * escaped, so nothing a path holds can end the element: each file with its
 * counts and the ids of its mutants, and what a carried file is, with where
 * the guide says more.
 *
 * Each file of a unit this run judged has its mutants in a script element of
 * their own, as gzip in base64, which the script inflates when the file is
 * first shown: each mutant with what it changed where that was kept, what it
 * came to, the gate's own judgement, the tests that killed it or how many
 * cover it, and, where it needs a test, what would kill it and how to run it
 * again. Each test and mutator is named once in a file's list, which its
 * mutants point into, and a mutant says nothing the page shows nothing of.
 *
 * @phpstan-import-type Report from JsonReport
 * @phpstan-import-type MutantEntry from MutantJson
 * @phpstan-import-type FileEntry from Mutants
 *
 * @phpstan-type Shown array{
 *     id: string,
 *     line: int,
 *     mutator: int,
 *     outcome: string,
 *     judgement: string,
 *     changed?: true,
 *     diff?: string,
 *     killedBy?: non-empty-list<int>,
 *     covered?: int,
 *     reason?: string,
 *     hint?: string,
 *     reproduce?: string,
 * }
 */
final readonly class Navigation
{
    private const string PACKED = '<script type="application/gzip" id="file-%d">%s</script>';

    /** The outcomes whose mutants need no test, which the page shows no hint or command for. */
    private const array SETTLED = [Outcome::Killed, Outcome::LeftOut];

    /**
     * @param Report          $report
     * @param list<FileEntry> $files
     */
    private function __construct(private array $report, private array $files, private Glossary $glossary)
    {
    }

    /** @param Report $report */
    public static function of(array $report, Mutants $mutants, Glossary $glossary): self
    {
        return new self($report, $mutants->files(), $glossary);
    }

    public function index(): string
    {
        $files = [];

        foreach ($this->files as $file) {
            $ids = [];

            foreach ($file['mutants'] as $mutant) {
                $ids[] = $mutant['id'];
            }

            $files[] = [
                'path' => $file['path'],
                'carried' => $file['carried'],
                'survivors' => $file['survivors'],
                'counts' => $file['counts'],
                'ids' => $ids,
            ];
        }

        $carried = Term::Carried;

        return json_encode(
            [
                'files' => $files,
                'carried' => ['meaning' => $carried->meaning(), 'guide' => $this->glossary->link($carried)],
            ],
            JsonText::FLAGS | JSON_HEX_TAG | JSON_HEX_AMP,
        );
    }

    /** The script element of each file that holds mutants, its mutants packed. */
    public function packed(): string
    {
        $elements = [];

        foreach ($this->files as $at => $file) {
            if ($file['mutants'] !== []) {
                $packed = Gzip::pack($this->fileJson($file));
                $elements[] = sprintf(self::PACKED, $at, sodium_bin2base64($packed, SODIUM_BASE64_VARIANT_ORIGINAL));
            }
        }

        return implode("\n", $elements);
    }

    /** @param FileEntry $file */
    private function fileJson(array $file): string
    {
        $tests = [];
        $mutators = [];
        $shown = [];

        foreach ($file['mutants'] as $mutant) {
            $killers = [];

            foreach ($mutant['killedBy'] as $test) {
                $name = $this->report['tests'][$test]['name'];
                $tests[$name] ??= count($tests);
                $killers[] = $tests[$name];
            }

            $mutators[$mutant['mutator']] ??= count($mutators);
            $shown[] = $this->shown($mutant, $mutators[$mutant['mutator']], $killers);
        }

        return JsonText::compact([
            'tests' => array_keys($tests),
            'mutators' => array_keys($mutators),
            'mutants' => $shown,
        ]);
    }

    /**
     * @param  MutantEntry $mutant
     * @param  list<int>   $killers where each test that killed it is in its file's list
     * @return Shown
     */
    private function shown(array $mutant, int $mutator, array $killers): array
    {
        $outcome = Mutants::outcomeOf($mutant);
        $open = ! in_array($outcome, self::SETTLED, strict: true);

        return [
            'id' => $mutant['id'],
            'line' => $mutant['line'],
            'mutator' => $mutator,
            'outcome' => $outcome->value,
            'judgement' => $mutant['judgement'],
            ...$mutant['changedLine'] ? ['changed' => true] : [],
            ...array_key_exists('diff', $mutant) ? ['diff' => $mutant['diff']] : [],
            ...$killers !== [] ? ['killedBy' => $killers] : [],
            ...$outcome !== Outcome::Killed ? ['covered' => count($mutant['coveredBy'])] : [],
            ...array_key_exists('reason', $mutant) ? ['reason' => $mutant['reason']] : [],
            ...$open ? ['hint' => $mutant['hint'], 'reproduce' => $mutant['reproduce']] : [],
        ];
    }
}
