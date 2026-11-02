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

    protected $fillable = ['name', 'slug', 'slack_webhook_url'];

    /**
     * The Slack webhook URL is a secret: anyone holding it can post to the
     * channel. Keep it encrypted at rest and out of serialized output.
     */
    protected $hidden = ['slack_webhook_url'];

    protected function casts(): array
    {
        return [
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
