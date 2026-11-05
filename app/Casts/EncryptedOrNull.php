<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Laravel's "encrypted" cast, except that a value that can't be decrypted any
 * more reads as null instead of throwing. That happens when APP_KEY changes
 * without APP_PREVIOUS_KEYS. Alerting and the edit page keep working, and
 * because this is a custom cast, saving a new value never decrypts the old one.
 */
class EncryptedOrNull implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString($value);
    }
}
