<?php

return [

    /*
    |--------------------------------------------------------------------------
    | In-app notifications (module 08 S4, spec 19)
    |--------------------------------------------------------------------------
    |
    | The bell is the one notification channel in this phase. The administrator
    | switch is a display preference, not an audit control: audit rows are
    | append-only and never silenced by this flag. Security notifications are
    | not exposed to this switch.
    |
    */

    'database_enabled' => true,

];
