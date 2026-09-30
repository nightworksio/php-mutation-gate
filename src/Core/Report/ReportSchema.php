<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;

use BackedEnum;

use function in_array;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;

/**
 * The JSON Schema of the gate's own report, built from the same enums the
 * report is written from, and committed at `resources/report.schema.json`.
 */
final readonly class ReportSchema
{
    private const string ID
        = 'https://github.com/nightworksio/php-mutation-gate/blob/main/resources/report.schema.json';

    private const string DRAFT = 'http://json-schema.org/draft-07/schema#';

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
            '$id' => self::ID,
            'title' => 'mutation-gate report',
            'description' => 'Everything a mutation-gate verdict decided (ADR-0009).',
            ...self::object([
                'format' => ['const' => JsonReport::FORMAT],
                'judgement' => self::oneOf(Judgement::Passed, Judgement::Failed),
                'cutShort' => self::FLAG,
                'uncovered' => self::oneOf(...Uncovered::cases()),
                'score' => self::PERCENT,
                'counts' => self::counts(),
                'trees' => self::listOf(self::tree()),
                'newCode' => self::listOf(self::newCode()),
                'mutants' => self::listOf(self::mutant()),
                'reach' => self::listOf(self::TEXT),
                'warnings' => self::listOf(self::TEXT),
                'failures' => self::listOf(self::TEXT),
            ], ['score']),
        ]);
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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
            'hint' => self::TEXT,
            'reproduce' => self::TEXT,
            'seconds' => self::SECONDS,
            'limit' => self::SECONDS,
        ], ['end', 'reason', 'seconds', 'limit']);
    }

    /** @return array<string, mixed> */
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
     * @param  array<string, mixed> $properties
     * @param  list<string>         $optional
     * @return array<string, mixed>
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
     * @param  array<string, mixed> $items
     * @return array<string, mixed>
     */
    private static function listOf(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /** @return array<string, mixed> */
    private static function oneOf(BackedEnum ...$cases): array
    {
        return ['enum' => array_map(static fn(BackedEnum $case): int|string => $case->value, $cases)];
    }
}
