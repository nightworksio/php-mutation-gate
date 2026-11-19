<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How the local daemon serves `watch` and `pre-push` and is started
 * (ADR-0078): how long it waits for a request and for a client to send one,
 * whether `watch` starts it, and how long `watch` waits for it to listen:
 * keys of `local`.
 */
final readonly class LocalServe
{
    /** How long a daemon waits for a request before it stops, by default. */
    private const int IDLE_MINUTES = 30;

    /** How long `watch` waits for a daemon it starts to listen, by default, in seconds. */
    private const float START_WAIT = 10.0;

    /** How long a client may take to send its request, by default, in seconds. */
    private const float REQUEST = 5.0;

    private function __construct(
        private Seconds|Absent $idle,
        private ServeStart|Absent $start,
        private Seconds|Absent $startWait,
        private Seconds|Absent $request,
    ) {
    }

    public static function of(
        Seconds|Absent $idle = new Absent(),
        ServeStart|Absent $start = new Absent(),
        Seconds|Absent $startWait = new Absent(),
        Seconds|Absent $request = new Absent(),
    ): self {
        return new self($idle, $start, $startWait, $request);
    }

    /** These settings, with what a later layer sets laid over them. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->idle, $later->idle),
            Absent::laid($this->start, $later->start),
            Absent::laid($this->startWait, $later->startWait),
            Absent::laid($this->request, $later->request),
        );
    }

    /** `local.serveIdle`: how long a daemon waits for a request before it stops. */
    public function idle(): Seconds
    {
        return $this->idle instanceof Seconds ? $this->idle : Seconds::minutes(self::IDLE_MINUTES);
    }

    /** `local.serveStart`: whether `watch` starts a daemon where none runs; it does by default. */
    public function start(): ServeStart
    {
        return $this->start instanceof ServeStart ? $this->start : ServeStart::Watch;
    }

    /** `local.serveStartWait`: how long `watch` waits for a daemon it starts to listen. */
    public function startWait(): Seconds
    {
        return $this->startWait instanceof Seconds ? $this->startWait : Seconds::of(self::START_WAIT);
    }

    /** `local.serveRequest`: how long a client may take to send a daemon its request. */
    public function request(): Seconds
    {
        return $this->request instanceof Seconds ? $this->request : Seconds::of(self::REQUEST);
    }

    /** The keys of `local` a layer sets of these, as it writes them. */
    public function written(): Json
    {
        return Json::object(
            Member::of('serveIdle', WholeSeconds::duration($this->idle)),
            Member::of('serveStart', $this->start instanceof ServeStart ? $this->start->value : $this->start),
            Member::of('serveStartWait', WholeSeconds::duration($this->startWait)),
            Member::of('serveRequest', WholeSeconds::duration($this->request)),
        );
    }

    /** The builder's calls that set these as a layer sets them. */
    public function php(): PhpCalls
    {
        return PhpCalls::duration('Local::serveIdle', $this->idle)
            ->and(PhpCalls::inWith(
                ...$this->start instanceof ServeStart
                    ? [sprintf('Local::serveStarting(ServeStart::%s)', $this->start->name)]
                    : [],
            ))
            ->and(PhpCalls::duration('Local::serveStartWait', $this->startWait))
            ->and(PhpCalls::duration('Local::serveRequest', $this->request));
    }
}
