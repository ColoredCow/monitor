<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Group extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'organization_id'];

    public function monitors()
    {
        return $this->hasMany(Monitor::class);
    }
}
