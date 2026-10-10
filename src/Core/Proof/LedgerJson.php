<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_flip;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;

use Closure;
use Generator;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * A ledger as the JSON text of its file ({@see LedgerFile}), written a
 * section at a time and its proofs one at a time, so that writing holds the
 * text and no more than one proof's record beside the ledger.
 *
 * @internal the shape of the ledger file
 *
 * @phpstan-import-type TimingRecord from LedgerFile
 */
final readonly class LedgerJson
{
    /**
     * The JSON text of what a ledger keeps under this retention, before it is gzipped; written a section at a
     * time, and its proofs one at a time, so that no more than one proof's record is held beside the text.
     */
    public static function text(Ledger $ledger, LedgerRetention $retention): string
    {
        return JsonText::object(self::sections($ledger, $retention));
    }

    /**
     * Each section of a ledger's file, as JSON text, in the order the file holds them.
     *
     * @return Generator<string, string>
     */
    private static function sections(Ledger $ledger, LedgerRetention $retention): Generator
    {
        $kept = $retention->proofsOf($ledger);
        $killers = $retention->killersOf($ledger);
        $mutators = self::namesOf($kept, static fn(Mutant|ProvedKill $killed): array => [$killed->mutator()]);
        $tests = array_values(array_unique([
            ...self::namesOf($kept, static fn(Mutant|ProvedKill $killed): array => self::idsOf($killed->killers())),
            ...KillersRecord::testsOf($killers),
            ...self::judgingOf($kept),
        ]));
        $testIndex = array_flip($tests);
        $inputs = InputsTable::of(...$kept);
        $timings = self::timings($ledger->timings());
        $analysers = AnalysersRecord::of($ledger->analysers());
        $survival = SurvivalRecord::of($ledger->survival());
        $passed = $ledger->runs()->passed();
        $lastRun = $ledger->runs()->lastRun();
        $proofs = self::proofsWritten($kept, array_flip($mutators), $testIndex, $inputs);

        yield 'format' => sprintf('%d', LedgerFile::FORMAT);
        yield LedgerFile::BASES => JsonText::compact(array_map(
            static fn(Digest $base): string => $base->value(),
            [...$retention->basesOf($ledger)],
        ));
        yield LedgerFile::MUTATORS => JsonText::compact($mutators);
        yield LedgerFile::TESTS => JsonText::compact($tests);
        yield InputsTable::SECTION => JsonText::compact($inputs->written());
        yield LedgerFile::PROOFS => JsonText::object($proofs);
        yield 'timings' => $timings === [] ? JsonText::object([]) : JsonText::compact($timings);
        yield KillersRecord::SECTION => JsonText::compact(KillersRecord::of($killers, $testIndex));

        if ($analysers !== []) {
            yield AnalysersRecord::SECTION => JsonText::compact($analysers);
        }

        if ($survival !== []) {
            yield SurvivalRecord::SECTION => JsonText::compact($survival);
        }

        if ($passed instanceof Passed) {
            yield LedgerFile::PASSED => JsonText::compact(PassedRecord::of($passed));
        }

        if ($lastRun instanceof LastRun) {
            yield LastRunRecord::SECTION => JsonText::compact(LastRunRecord::of($lastRun));
        }
    }

    /**
     * Each kept proof's record, as JSON text, by its key.
     *
     * @param  list<Proof>        $kept
     * @param  array<string, int> $mutators each mutator's index in the ledger, by its name
     * @param  array<string, int> $tests    each killing test's index in the ledger, by its id
     * @return Generator<string, string>
     */
    private static function proofsWritten(array $kept, array $mutators, array $tests, InputsTable $inputs): Generator
    {
        foreach ($kept as $proof) {
            yield $proof->key()->value() => JsonText::compact(ProofRecord::of($proof, $mutators, $tests, $inputs));
        }
    }

    /**
     * What the killed mutants of these proofs name, each once, in the order
     * they first name it: their mutators, or the tests that killed them.
     *
     * @param  list<Proof>                              $proofs
     * @param  Closure(Mutant|ProvedKill): list<string> $named
     * @return list<string>
     */
    private static function namesOf(array $proofs, Closure $named): array
    {
        $names = [];

        foreach ($proofs as $proof) {
            $names[] = self::killedNamesIn($proof, $named);
        }

        return array_values(array_unique(array_merge(...$names)));
    }

    /**
     * What the killed mutants of one proof name, reported or proved.
     *
     * @param  Closure(Mutant|ProvedKill): list<string> $named
     * @return list<string>
     */
    private static function killedNamesIn(Proof $proof, Closure $named): array
    {
        $names = [];

        foreach ($proof->reported() as $mutant) {
            $names[] = $mutant->status() === MutantStatus::Killed ? $named($mutant) : [];
        }

        foreach ($proof->kills() as $kill) {
            $names[] = $named($kill);
        }

        return array_merge(...$names);
    }

    /**
     * The holding tests every kept proof of a held unit names, each once.
     *
     * @param  list<Proof>  $kept
     * @return list<string>
     */
    private static function judgingOf(array $kept): array
    {
        $tests = [];

        foreach ($kept as $proof) {
            $tests = [...$tests, ...self::idsOf($proof->judging())];
        }

        return $tests;
    }

    /** @return list<string> */
    private static function idsOf(TestIds $tests): array
    {
        return array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
    }


    /** @return array<string, TimingRecord> */
    private static function timings(Timings $timings): array
    {
        $written = [];

        foreach ($timings as $timing) {
            $written[$timing->unit()->value()] = [
                LedgerFile::SECONDS => $timing->seconds()->seconds(),
                LedgerFile::RUNNER => $timing->runner(),
                LedgerFile::AT => $timing->at()->value(),
                ...$timing->gate()->isRecorded() ? [LedgerFile::GATE => $timing->gate()->value()] : [],
            ];
        }

        return $written;
    }
}
