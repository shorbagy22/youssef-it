<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-managed department: owns Sources and has its own public chat
 * page at /chat/{slug}. Manageable from /admin/departments.
 *
 * The table is the single source of truth for source registration, public
 * pages, and /api/chat validation. The legacy value-object enum is used
 * only to seed the application's four initial departments.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }
}
