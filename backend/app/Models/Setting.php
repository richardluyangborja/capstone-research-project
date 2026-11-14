<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One persisted setting override (module 08 slice S4, spec 19, ADR-0030).
 *
 * The allow-list itself is `App\Settings\SettingRegistry`; a row here is only an override.
 * The key is a registry key — never a free-form name — and the value is whatever type the
 * registry declares for it. Secrets are never rows: their only source is the environment
 * (spec 19 §Settings allow-list, "never settings").
 *
 * @property string $id
 */
class Setting extends Model
{
    use HasUlids;

    protected $fillable = ['scope', 'scope_id', 'key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
