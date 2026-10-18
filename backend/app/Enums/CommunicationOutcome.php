<?php

namespace App\Enums;

/**
 * How a call or meeting ended (spec 11 §Channels). Null on channels where it is meaningless.
 */
enum CommunicationOutcome: string
{
    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case Voicemail = 'voicemail';
    case Busy = 'busy';
    case Other = 'other';
}
