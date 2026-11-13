<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function ctype_digit;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The options of `watch` that lay keys of `local` (ADR-0146): `--rounds`,
 * which ends it after that many rounds, and `--from-map`, which names the
 * directory of a map its first round measures against.
 *
 * @internal
 */
final readonly class RoundOptions
{
    private const string ROUNDS = 'rounds';

    private const string FROM_MAP = 'from-map';

    /** The command, taking both options. */
    public static function on(Command $command): Command
    {
        return $command
            ->addOption(
                self::ROUNDS,
                mode: InputOption::VALUE_REQUIRED,
                description: 'End after this many rounds (local.rounds)',
            )
            ->addOption(
                self::FROM_MAP,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Measure the first round against the map `coverage --into` wrote here (local.fromMap)',
            );
    }

    /**
     * The keys of `local` the options set, where the command takes them; a
     * count that is no whole number stays text, which the config refuses by
     * its key.
     *
     * @return list<Member>
     */
    public static function laid(InputInterface $input): array
    {
        $rounds = self::given($input, self::ROUNDS);
        $fromMap = self::given($input, self::FROM_MAP);
        $members = $rounds instanceof NotGiven
            ? []
            : [Member::of(self::ROUNDS, ctype_digit($rounds) ? (int) $rounds : $rounds)];

        return $fromMap instanceof NotGiven ? $members : [...$members, Member::of('fromMap', $fromMap)];
    }

    /** An option's text, or nothing given where the command does not take it or it is left off. */
    private static function given(InputInterface $input, string $name): string|NotGiven
    {
        $value = $input->hasOption($name) ? $input->getOption($name) : NotGiven::value();

        return is_string($value) ? $value : NotGiven::value();
    }
}
