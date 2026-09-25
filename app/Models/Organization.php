<?php

namespace App\Models;

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

    public const CREDIT_LEVEL_NONE = 'none';

    public const CREDIT_LEVEL_LOW = 'low';

    public const CREDIT_LEVEL_CRITICAL = 'critical';

    public const CREDIT_LEVEL_EXHAUSTED = 'exhausted';

    protected $fillable = ['name', 'slug'];

    // NOTE: credit_balance and credit_warning_level are deliberately NOT
    // fillable — they change only through CreditLedgerService / CreditMeteringService.

    protected $casts = [
        'credit_balance' => 'integer',
    ];

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

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function creditUsage(): HasMany
    {
        return $this->hasMany(CreditUsageDaily::class);
    }

    public function admins(): BelongsToMany
    {
        return $this->users()->wherePivot('role', self::ROLE_ADMIN);
    }

    public function hasCredits(): bool
    {
        return $this->credit_balance > 0;
    }
}
