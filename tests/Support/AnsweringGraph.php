<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;
use NightWorksIO\MutationGate\Core\Reach\ReachGraph;
use NightWorksIO\MutationGate\Port\MapEngine;
use NightWorksIO\MutationGate\Tests\Fakes\MapEngineFake;

/** A map engine whose static graph answers every question with one answer, keeping each it was asked, and which vouches for no map. */
final class AnsweringGraph implements MapEngine, ReachGraph
{
    /** @var list<GraphQuestion> */
    public private(set) array $questions = [];

    public function __construct(private readonly GraphAnswer|NotWritten $answer)
    {
    }

    public function vouches(Contents $text): MapVouch
    {
        return new MapEngineFake()->vouches($text);
    }

    public function split(Path $whole, MapSlices $slices): NotWritten
    {
        return new MapEngineFake()->split($whole, $slices);
    }

    public function answered(GraphQuestion $question): GraphAnswer|NotWritten
    {
        $this->questions[] = $question;

        return $this->answer;
    }
}
