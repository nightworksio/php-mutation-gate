<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function implode;
use function intval;

use NightWorksIO\MutationGate\Core\Analysis\DependentCap;
use NightWorksIO\MutationGate\Core\Analysis\PreCheck;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * The static analyser that may kill a mutant, and the config it reads
 * (ADR-0020): `staticCheck`. The analyser is chosen as any other
 * adapter is, by a name or a class. `auto` takes the first one installed and
 * configured, and `none` turns checking off; both are names, not adapters.
 * Without a config, the analyser discovers its own. One check may take
 * `staticCheck.seconds`, and so may Psalm's language server to start.
 * `staticCheck.before` also checks a mutant before its tests where that pays;
 * on by default (ADR-0020, ADR-0077), weighed against the time of its tests
 * `staticCheck.testsTime` names.
 */
final readonly class StaticCheck implements Part
{
    /** The analyser zero-config finds: the first installed and configured, or none. */
    public const string AUTO = 'auto';

    /** No analyser: every mutant goes to its tests alone. */
    public const string NONE = 'none';

    /** How many programs one opcache child compiles, where the config does not say. */
    public const int OPCACHE_BATCH = 100;

    /** How long one opcache child may take to compile its programs, in seconds, where the config does not say. */
    public const float OPCACHE_LIMIT = 60.0;

    private function __construct(
        private Choice|Absent $tool,
        private Path|Absent $config,
        private Seconds|Absent $seconds,
        private bool|Absent $before,
        private TestsTime|Absent $testsTime,
        private int|Absent $opcacheBatch,
        private Seconds|Absent $opcacheLimit,
        private int|Absent $dependents,
        private int|Absent $preCheckAfter,
        private Json|Absent $php,
    ) {
    }

    public static function of(
        Choice|Absent $tool = new Absent(),
        Path|Absent $config = new Absent(),
        Seconds|Absent $seconds = new Absent(),
        bool|Absent $before = new Absent(),
        TestsTime|Absent $testsTime = new Absent(),
        int|Absent $opcacheBatch = new Absent(),
        Seconds|Absent $opcacheLimit = new Absent(),
        int|Absent $dependents = new Absent(),
        int|Absent $preCheckAfter = new Absent(),
        Json|Absent $php = new Absent(),
    ): self {
        return new self(
            $tool,
            $config,
            $seconds,
            $before,
            $testsTime,
            $opcacheBatch,
            $opcacheLimit,
            $dependents,
            $preCheckAfter,
            $php,
        );
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of(
            $none->tool(),
            seconds: $none->seconds(),
            before: $none->before(),
            testsTime: $none->testsTime(),
            opcacheBatch: $none->opcacheBatch(),
            opcacheLimit: $none->opcacheLimit(),
            dependents: $none->dependents(),
            preCheckAfter: $none->preCheckAfter(),
            php: $none->php(),
        );
    }

    /** An analyser is chosen whole, and so is the config it reads. */
    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->tool, $later->tool),
                Absent::laid($this->config, $later->config),
                Absent::laid($this->seconds, $later->seconds),
                Absent::laid($this->before, $later->before),
                Absent::laid($this->testsTime, $later->testsTime),
                Absent::laid($this->opcacheBatch, $later->opcacheBatch),
                Absent::laid($this->opcacheLimit, $later->opcacheLimit),
                Absent::laid($this->dependents, $later->dependents),
                Absent::laid($this->preCheckAfter, $later->preCheckAfter),
                Absent::laid($this->php, $later->php),
            )
            : $this;
    }

    /**
     * `staticCheck.php`: the ini settings each of the analyser's checks runs under, which change none of its
     * findings: pcov off, opcache on with a file cache alone and no timestamp checks, where no layer says
     * (ADR-0020).
     */
    public function php(): Json
    {
        return $this->php instanceof Json ? $this->php : Json::object(
            Member::of('pcov.enabled', '0'),
            Member::of('opcache.enable_cli', '1'),
            Member::of('opcache.file_cache_only', '1'),
            Member::of('opcache.validate_timestamps', '0'),
        );
    }

    /** `staticCheck.tool`: the analyser chosen, `auto` where no layer chooses one. */
    public function tool(): Choice
    {
        return $this->tool instanceof Choice ? $this->tool : Choice::of(self::AUTO, Options::none());
    }

    /** `staticCheck.config`: the config the analyser reads, or none, where it discovers its own. */
    public function config(): Path|Absent
    {
        return $this->config;
    }

    /**
     * `staticCheck.seconds`: how long one check may take, and Psalm's
     * language server to start; a minute by default.
     */
    public function seconds(): Seconds
    {
        return $this->seconds instanceof Seconds ? $this->seconds : Seconds::of(Seconds::PER_MINUTE);
    }

    /**
     * `staticCheck.before`: whether a mutant is also checked before its tests
     * where that pays; yes by default (ADR-0077), and no by default where the
     * run splits its streams, as a kill there costs less than a check
     * (ADR-0117).
     */
    public function before(): bool
    {
        return $this->before !== false;
    }

    /**
     * `staticCheck.testsTime`: the time of a mutant's tests a check before
     * them is weighed against; what the ledgers learned it costs by default
     * (ADR-0077).
     */
    public function testsTime(): TestsTime
    {
        return $this->testsTime instanceof TestsTime ? $this->testsTime : TestsTime::Learned;
    }

    /** `staticCheck.opcache.batch`: the programs one child compiles, so opcache's memory never fills. */
    public function opcacheBatch(): int
    {
        return $this->opcacheBatch instanceof Absent ? self::OPCACHE_BATCH : $this->opcacheBatch;
    }

    /** `staticCheck.opcache.limit`: the longest one child may compile for. */
    public function opcacheLimit(): Seconds
    {
        return $this->opcacheLimit instanceof Seconds ? $this->opcacheLimit : Seconds::of(self::OPCACHE_LIMIT);
    }

    /** `staticCheck.dependents`: how many dependents of a changed file an analyser reads at most. */
    public function dependents(): int
    {
        return $this->dependents instanceof Absent ? DependentCap::STANDARD : $this->dependents;
    }

    /** `staticCheck.preCheckAfter`: how many checks of a mutator are made before its rate decides where it goes. */
    public function preCheckAfter(): int
    {
        return $this->preCheckAfter instanceof Absent ? PreCheck::STANDARD : $this->preCheckAfter;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'staticCheck',
            Json::object(
                Member::of('tool', $this->tool instanceof Choice ? $this->tool->written() : $this->tool),
                Member::of('config', $this->config instanceof Path ? $origin->written($this->config) : $this->config),
                Member::of('seconds', WholeSeconds::written($this->seconds)),
                Member::of('before', $this->before),
                Member::of(
                    'testsTime',
                    $this->testsTime instanceof TestsTime ? $this->testsTime->value : $this->testsTime,
                ),
                Member::of('dependents', $this->dependents),
                Member::of('preCheckAfter', $this->preCheckAfter),
                Member::of('php', $this->php),
                Member::unlessEmpty(
                    'opcache',
                    Json::object(
                        Member::of('batch', $this->opcacheBatch),
                        Member::of('limit', WholeSeconds::duration($this->opcacheLimit)),
                    ),
                ),
            ),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->tool instanceof Choice
                ? [PhpCalls::chosen($this->tool, AdapterBuilder::StaticCheck, ...$this->builtIn())]
                : [],
            ...$this->config instanceof Path
                ? [sprintf('StaticCheck::config(%s)', PhpCalls::literal($origin->written($this->config)))]
                : [],
            ...$this->seconds instanceof Seconds
                ? [sprintf('StaticCheck::seconds(%d)', intval($this->seconds->seconds()))]
                : [],
            ...$this->before === true ? ['StaticCheck::beforeTests()'] : [],
            ...$this->before === false ? ['StaticCheck::afterTests()'] : [],
            ...$this->testsTime instanceof TestsTime ? [$this->testsTime->php()] : [],
            ...$this->learningPhp(),
            ...$this->php instanceof Json
                ? [sprintf('StaticCheck::php(%s)', implode(', ', PhpOptions::of($this->php)))]
                : [],
            ...$this->opcacheBatch instanceof Absent
                ? []
                : [sprintf('StaticCheck::opcacheBatch(%d)', $this->opcacheBatch)],
            ...$this->opcacheLimit instanceof Seconds
                ? [sprintf('StaticCheck::opcacheLimit(%s)', PhpCalls::literal($this->opcacheLimit->written()))]
                : [],
        ]);
    }

    /** @return list<string> the builder's calls for `staticCheck.dependents` and `staticCheck.preCheckAfter` */
    private function learningPhp(): array
    {
        return [
            ...$this->dependents instanceof Absent
                ? []
                : [sprintf('StaticCheck::dependents(%d)', $this->dependents)],
            ...$this->preCheckAfter instanceof Absent
                ? []
                : [sprintf('StaticCheck::preCheckAfter(%d)', $this->preCheckAfter)],
        ];
    }

    /** @return list<string> `auto`, `none` and the analysers built in, each with a builder method of its own */
    private function builtIn(): array
    {
        return [
            self::AUTO,
            self::NONE,
            ...array_map(static fn(BuiltinAnalyser $analyser): string => $analyser->value, BuiltinAnalyser::cases()),
        ];
    }
}
