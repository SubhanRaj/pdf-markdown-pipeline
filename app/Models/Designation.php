<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Designation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'department_id',
        'name',
        'slug',
        'default_scope',
        'default_privileges',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_privileges' => 'array',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** True if this designation is generic (any department) or locked to the given department. */
    public function appliesToDepartment(?int $departmentId): bool
    {
        return $this->department_id === null || $this->department_id === $departmentId;
    }
}
