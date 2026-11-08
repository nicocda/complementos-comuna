<?php

namespace App\Models;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $internal_number
 * @property UnitType $type
 * @property string $name
 * @property string|null $license_plate
 * @property UnitStatus $status
 * @property int|null $attached_to_unit_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['internal_number', 'type', 'name', 'license_plate', 'status', 'attached_to_unit_id', 'notes'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'internal_number' => 'integer',
            'type' => UnitType::class,
            'status' => UnitStatus::class,
        ];
    }

    /**
     * Human readable description, e.g. "Tractor Massey Ferguson 1175 · Interno N° 1".
     */
    public function label(): string
    {
        $number = $this->internal_number !== null ? "Interno N° {$this->internal_number}" : 'S/N';

        return "{$this->type->getLabel()} {$this->name} · {$number}";
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function attachedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'attached_to_unit_id');
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function implements(): HasMany
    {
        return $this->hasMany(self::class, 'attached_to_unit_id');
    }

    /**
     * @return BelongsToMany<Driver, $this>
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class);
    }

    /**
     * @return HasMany<RepairRequest, $this>
     */
    public function repairRequests(): HasMany
    {
        return $this->hasMany(RepairRequest::class);
    }
}
