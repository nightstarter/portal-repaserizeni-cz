<?php

namespace App\Models;

use App\Enums\OrganizationCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'active'];

    protected function casts(): array
    {
        return ['code' => OrganizationCode::class, 'active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
