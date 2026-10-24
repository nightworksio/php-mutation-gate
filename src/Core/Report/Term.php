<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Format\Fit;

/**
 * A word the reports use that a reader new to mutation testing may not know,
 * with what it means in plain English: the HTML report shows it behind a
 * "what is this?" where the word appears, the digest lists them all as its
 * legend, and the guide explains each under a heading of its value
 * (ADR-0009).
 */
enum Term: string
{
    case MutationTesting = 'mutation-testing';
    case Mutant = 'mutant';
    case Killed = 'killed';
    case Escaped = 'escaped';
    case Untested = 'untested';
    case TimedOut = 'timed-out';
    case Errored = 'errored';
    case LeftOut = 'left-out';
    case Score = 'score';
    case Floor = 'floor';
    case Carried = 'carried';

    public function meaning(): string
    {
        return Fit::plain(match ($this) {
            self::MutationTesting => <<<'TEXT'
                Mutation testing checks your tests, not your code: the gate makes small changes to your
                code, called mutants, and runs your tests against each one. A good test suite fails on every
                change.
                TEXT,
            self::Mutant => <<<'TEXT'
                A copy of your code with one small change, such as `>` turned into `>=` or a line removed.
                TEXT,
            self::Killed => 'A mutant your tests caught: at least one test failed with the change in place.',
            self::Escaped => 'A mutant your tests missed: the tests ran the changed code and still passed.',
            self::Untested => 'A mutant in code that no test runs at all.',
            self::TimedOut => <<<'TEXT'
                A mutant that made the tests run far longer than usual, such as by causing an endless loop.
                TEXT,
            self::Errored => 'A mutant that crashed the test run instead of failing a test.',
            self::LeftOut => <<<'TEXT'
                A mutant the score does not count: ignored in the config, marked in the code, or proven to
                behave exactly like the original.
                TEXT,
            self::Score => 'The share of counted mutants your tests caught, in percent. Higher is better.',
            self::Floor => <<<'TEXT'
                The lowest score a part of the code may have. A run fails when a score drops below its
                floor, and the floor rises as the score does.
                TEXT,
            self::Carried => <<<'TEXT'
                A result reused from an earlier run because nothing it depends on changed, so this run did
                not test it again.
                TEXT,
        });
    }
}
