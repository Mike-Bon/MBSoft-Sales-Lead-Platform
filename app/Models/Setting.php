<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12A: a generic key/value application setting. `getValue()`/
 * `setValue()` are the only intended access path — never query this
 * model's rows directly from a controller or tool, so there is exactly
 * one place a setting's shape (a plain string, coerced by the caller)
 * is defined. Not itself an authorization boundary: whichever service
 * calls setValue() is responsible for having already authorized the
 * actor (see CostToServeAccessService).
 */
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function setValue(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
