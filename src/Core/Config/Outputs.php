<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * What a run writes beside the reports a config lists: `outputs`, which the `Reports` part holds. `verdictOnly` writes
 * neither the reports where the run is nor those the action asks for; `automatic` names those a run writes because
 * of where it is; `actionReports` those the action asks for; `costs` whether the reports say what the run cost.
 */
final readonly class Outputs
{
    /** The reporters a run writes because of where it is, where the config does not say. */
    private const array AUTOMATIC = [
        BuiltinReporter::GitHubAnnotations->value,
        BuiltinReporter::GitHubSummary->value,
        BuiltinReporter::GitHubComment->value,
        BuiltinReporter::Badge->value,
    ];

    /** The reports the action asks of the verdict, where the config does not say. */
    private const array ACTION_REPORTS = [
        BuiltinReporter::Json->value,
        BuiltinReporter::Html->value,
        BuiltinReporter::Digest->value,
    ];

    /**
     * @param Listed<string>|Absent $automatic
     * @param Listed<string>|Absent $actionReports
     */
    private function __construct(
        private bool|Absent $verdictOnly,
        private Listed|Absent $automatic,
        private Listed|Absent $actionReports,
        private bool|Absent $costs,
    ) {
    }

    /**
     * @param Listed<string>|Absent $automatic
     * @param Listed<string>|Absent $actionReports
     */
    public static function of(
        bool|Absent $verdictOnly = new Absent(),
        Listed|Absent $automatic = new Absent(),
        Listed|Absent $actionReports = new Absent(),
        bool|Absent $costs = new Absent(),
    ): self {
        return new self($verdictOnly, $automatic, $actionReports, $costs);
    }

    /** Every key at the value it takes where every layer leaves it out. */
    public static function standard(): self
    {
        return new self(
            verdictOnly: false,
            automatic: Listed::of(...self::AUTOMATIC),
            actionReports: Listed::of(...self::ACTION_REPORTS),
            costs: true,
        );
    }

    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->verdictOnly, $later->verdictOnly),
            Absent::laid($this->automatic, $later->automatic),
            Absent::laid($this->actionReports, $later->actionReports),
            Absent::laid($this->costs, $later->costs),
        );
    }

    /**
     * The names of the reporters a run writes because of where it is: none under `outputs.verdictOnly`.
     *
     * @return Listed<string>
     */
    public function automatic(): Listed
    {
        return match (true) {
            $this->verdictOnly === true => Listed::of(),
            $this->automatic instanceof Listed => $this->automatic,
            default => Listed::of(...self::AUTOMATIC),
        };
    }

    /** What a run's account says in its reports: nothing of what it cost, under `outputs.costs: false`. */
    public function said(RunAccount $account): RunAccount
    {
        return $this->costs === false ? $account->uncosted() : $account;
    }

    /** The `outputs` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        return Member::unlessEmpty(
            'outputs',
            Json::object(
                Member::of('verdictOnly', $this->verdictOnly),
                Member::of('automatic', $this->names($this->automatic)),
                Member::of('actionReports', $this->names($this->actionReports)),
                Member::of('costs', $this->costs),
            ),
        );
    }

    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...match ($this->verdictOnly) {
                true => ['Outputs::verdictOnly()'],
                false => ['Outputs::everyOutput()'],
                default => [],
            },
            ...$this->called('Outputs::automatic(%s)', $this->automatic),
            ...$this->called('Outputs::actionReports(%s)', $this->actionReports),
            ...match ($this->costs) {
                true => ['Outputs::withCosts()'],
                false => ['Outputs::withoutCosts()'],
                default => [],
            },
        ]);
    }

    /**
     * @param  Listed<string>|Absent $reporters
     * @return list<string>
     */
    private function called(string $call, Listed|Absent $reporters): array
    {
        return $reporters instanceof Absent ? [] : [sprintf($call, PhpCalls::literals(...$reporters))];
    }

    /** @param Listed<string>|Absent $reporters */
    private function names(Listed|Absent $reporters): Json|Absent
    {
        return $reporters instanceof Absent ? $reporters : Json::items(...$reporters);
    }
}
