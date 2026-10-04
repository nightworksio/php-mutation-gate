<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel;

/** The facades the set's mutators call through, each by the short name its class and its global alias share. */
enum Facade: string
{
    case Auth = 'Auth';
    case Bus = 'Bus';
    case Cache = 'Cache';
    case Db = 'DB';
    case Event = 'Event';
    case Gate = 'Gate';
    case Hash = 'Hash';
    case Mail = 'Mail';
    case Notification = 'Notification';
    case Validator = 'Validator';
}
