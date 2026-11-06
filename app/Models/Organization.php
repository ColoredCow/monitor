<?php

namespace App\Models;

use App\Casts\EncryptedOrNull;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    /** The alert services an organization can set its own webhook for. */
    public const WEBHOOK_SERVICES = ['google_chat', 'slack'];

    protected $fillable = ['name', 'slug', 'google_chat_webhook_url', 'slack_webhook_url'];

    /**
     * Webhook URLs are secrets: anyone holding one can post to the space or
     * channel. Keep them encrypted at rest and out of serialized output.
     */
    protected $hidden = ['google_chat_webhook_url', 'slack_webhook_url'];

    protected function casts(): array
    {
        return [
            'google_chat_webhook_url' => EncryptedOrNull::class,
            'slack_webhook_url' => EncryptedOrNull::class,
        ];
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
