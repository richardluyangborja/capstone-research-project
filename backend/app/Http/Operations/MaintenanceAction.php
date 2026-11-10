<?php

namespace App\Http\Operations;

/**
 * Marker for the allow-listed maintenance actions (module 08 slice S7, spec 19 §S7).
 *
 * The class carries no record and no state: it exists so the two administrative actions have
 * an explicit model-less policy target (`MaintenanceActionPolicy`) instead of an ability defined
 * ad hoc in a controller. There is deliberately no generic command runner, SQL console, cache
 * flush, migration, backup or restore action behind it.
 */
final class MaintenanceAction
{
    private function __construct() {}
}
