<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function array_values;

use BackedEnum;

use function in_array;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\Standing;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;

use function sprintf;

/**
 * The JSON Schemas of the gate's own reports, built from the same enums the
 * reports are written from, and committed at `resources/report.schema.json`
 * and `resources/tests.schema.json`.
 *
 * @phpstan-type Leaf array{
 *     type?: string,
 *     minimum?: int,
 *     maximum?: int,
 *     pattern?: string,
 *     const?: int,
 *     enum?: list<int|string>,
 * }
 * @phpstan-type Flat array{
 *     type: string,
 *     properties: array<string, Leaf>,
 *     required: list<string>,
 *     additionalProperties: bool,
 * }
 * @phpstan-type Listed array{type: string, items: Leaf}
 * @phpstan-type Shallow array{
 *     type: string,
 *     properties: array<string, Leaf|Flat|Listed>,
 *     required: list<string>,
 *     additionalProperties: bool,
 * }
 * @phpstan-type Nested array{type: string, items: Shallow}
 */
final readonly class ReportSchema
{
    private const string ID
        = 'https://github.com/nightworksio/php-mutation-gate/blob/main/resources/%s.schema.json';

    private const string DRAFT = 'http://json-schema.org/draft-07/schema#';

    private const string TESTS
        = 'The tests mutation says catch nothing, and those that can go without losing a kill (ADR-0014).';

    private const array TEXT = ['type' => 'string'];

    private const array WHOLE = ['type' => 'integer', 'minimum' => 0];

    private const array LINE = ['type' => 'integer', 'minimum' => 1];

    private const array SECONDS = ['type' => 'number', 'minimum' => 0];

    private const array PERCENT = ['type' => 'number', 'minimum' => 0, 'maximum' => 100];

    private const array FLAG = ['type' => 'boolean'];

    private const array ID_SPELLING = ['type' => 'string', 'pattern' => '^[0-9a-f]{12}$'];

    public static function json(): string
    {
        return Json::encode([
            '$schema' => self::DRAFT,
            '$id' => sprintf(self::ID, 'report'),
            'title' => 'mutation-gate report',
            'description' => 'Everything a mutation-gate verdict decided (ADR-0009).',
            ...self::object([
                'format' => ['const' => JsonReport::FORMAT],
                'judgement' => self::oneOf(Judgement::Passed, Judgement::Failed),
                'cutShort' => self::FLAG,
                'uncovered' => self::oneOf(...Uncovered::cases()),
                'score' => self::PERCENT,
                'counts' => self::counts(),
                'trees' => self::listOfObjects(self::tree()),
                'newCode' => self::listOfObjects(self::newCode()),
                'matrix' => self::oneOf(...MatrixKind::cases()),
                'tests' => self::listOf(self::object([
                    'id' => self::TEXT,
                    'name' => self::TEXT,
                    'file' => self::TEXT,
                    'row' => self::TEXT,
                    'seconds' => self::SECONDS,
                ], ['file', 'row', 'seconds'])),
                'mutants' => self::listOfObjects(self::mutant()),
                'reach' => self::listOf(self::TEXT),
                'warnings' => self::listOf(self::TEXT),
                'failures' => self::listOf(self::TEXT),
            ], ['score']),
        ]);
    }

    /** The schema of the `tests` report: the tests mutation says are useless, and those that can go (ADR-0014). */
    public static function tests(): string
    {
        $row = self::object(['name' => self::TEXT, 'standing' => self::oneOf(...Standing::cases())], []);
        $test = self::object([
            'test' => self::TEXT,
            'standing' => self::oneOf(Standing::KillsNothing, Standing::NeverFirst),
            'covers' => self::WHOLE,
            'rows' => ['type' => 'array', 'items' => $row],
        ], ['rows']);
        $kill = self::object(['mutant' => self::ID_SPELLING, 'keptBy' => self::TEXT], []);
        $removable = self::object([
            'test' => self::TEXT,
            'seconds' => self::SECONDS,
            'kills' => ['type' => 'array', 'items' => $kill],
        ], ['seconds']);

        return Json::encode([
            '$schema' => self::DRAFT,
            '$id' => sprintf(self::ID, 'tests'),
            'title' => 'mutation-gate tests report',
            'description' => self::TESTS,
            ...self::object([
                'format' => ['const' => TestsReport::FORMAT],
                'matrix' => self::oneOf(...MatrixKind::cases()),
                'useless' => ['type' => 'array', 'items' => $test],
                'notAssessed' => self::WHOLE,
                'redundant' => ['oneOf' => [
                    self::object(['needs' => self::TEXT], []),
                    self::object([
                        'kept' => self::listOf(self::TEXT),
                        'removable' => ['type' => 'array', 'items' => $removable],
                    ], []),
                ]],
            ], []),
        ]);
    }

    /** @return Shallow */
    private static function tree(): array
    {
        return self::object([
            'path' => self::TEXT,
            'package' => self::TEXT,
            'declared' => self::PERCENT,
            'exempt' => self::TEXT,
            'baseline' => self::PERCENT,
            'floor' => self::PERCENT,
            'score' => self::PERCENT,
            'base' => self::PERCENT,
            'raised' => self::PERCENT,
            'judgement' => self::oneOf(...Judgement::cases()),
            'counts' => self::counts(),
            'units' => self::listOf(self::object([
                'path' => self::TEXT,
                'group' => self::TEXT,
                'filter' => self::TEXT,
                'origin' => self::oneOf(...Origin::cases()),
            ], ['group', 'filter'])),
            'mutants' => self::listOf(self::ID_SPELLING),
        ], ['declared', 'exempt', 'baseline', 'floor', 'score', 'base', 'raised']);
    }

    /** @return Shallow */
    private static function newCode(): array
    {
        return self::object([
            'package' => self::TEXT,
            'floor' => self::PERCENT,
            'score' => self::PERCENT,
            'judgement' => self::oneOf(Judgement::Passed, Judgement::Failed, Judgement::NothingToMutate),
            'counts' => self::counts(),
            'mutants' => self::listOf(self::ID_SPELLING),
        ], ['score']);
    }

    /** @return Shallow */
    private static function mutant(): array
    {
        return self::object([
            'id' => self::ID_SPELLING,
            'file' => self::TEXT,
            'line' => self::LINE,
            'end' => self::LINE,
            'mutator' => self::TEXT,
            'family' => self::oneOf(...MutatorFamily::cases()),
            'diff' => self::TEXT,
            'status' => self::oneOf(...MutantStatus::cases()),
            'judgement' => self::oneOf(...MutantJudgement::cases()),
            'reason' => self::TEXT,
            'changedLine' => self::FLAG,
            'tests' => self::listOf(self::TEXT),
            'coveredBy' => self::listOf(self::WHOLE),
            'killedBy' => self::listOf(self::WHOLE),
            'hint' => self::TEXT,
            'reproduce' => self::TEXT,
            'explain' => self::TEXT,
            'seconds' => self::SECONDS,
            'limit' => self::SECONDS,
        ], ['end', 'reason', 'seconds', 'limit']);
    }

    /** @return Flat */
    private static function counts(): array
    {
        $numbers = [];

        foreach (MutantJudgement::cases() as $judgement) {
            $numbers[$judgement->value] = self::WHOLE;
        }

        return self::object($numbers, []);
    }

    /**
     * An object with exactly these properties, all required but the optional ones.
     *
     * @template P
     *
     * @param  array<string, P> $properties
     * @param  list<string>     $optional
     * @return array{type: string, properties: array<string, P>, required: list<string>, additionalProperties: bool}
     */
    private static function object(array $properties, array $optional): array
    {
        $required = [];

        foreach ($properties as $name => $property) {
            $required = in_array($name, $optional, strict: true) ? $required : [...$required, $name];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * A list of values, or of objects with no object inside.
     *
     * @param  Leaf $items
     * @return Listed
     */
    private static function listOf(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /**
     * A list of objects.
     *
     * @param  Shallow $items
     * @return Nested
     */
    private static function listOfObjects(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /** @return array{enum: list<int|string>} */
    private static function oneOf(BackedEnum ...$cases): array
    {
        return ['enum' => array_values(array_map(static fn(BackedEnum $case): int|string => $case->value, $cases))];
    }
}
