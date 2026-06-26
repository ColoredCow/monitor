<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['name', 'organization_id'];

    public function monitors()
    {
        return $this->hasMany(Monitor::class);
    }
}
