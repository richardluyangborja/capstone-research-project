<?php

namespace App\Enums;

/**
 * Which way the message travelled (spec 11 §Channels).
 */
enum CommunicationDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
