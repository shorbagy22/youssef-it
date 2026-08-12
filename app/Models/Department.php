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
 * This is deliberately separate from the fixed ValueObjects\Department
 * enum, which /api/chat and data_records still key off - this table is
 * the dynamic side of "departments" (Sources, the public dashboard, and
 * /chat/{slug} page rendering); the enum remains the static side (the AI
 * chat API and its data pipeline), seeded here with matching slugs so the
 * two stay aligned for the four departments both sides know about.
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
     * @return HasMany<Source>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }
}
