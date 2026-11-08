<?php

namespace App\Models;

use App\Enums\ProblemKind;
use App\Enums\RepairStatus;
use App\Enums\RepairUrgency;
use App\Support\Permissions;
use Database\Factories\RepairRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Mass assignment is intentionally closed: every write goes through
 * App\Services\RepairWorkflow, which sets attributes explicitly.
 *
 * @property int $id
 * @property int $number
 * @property RepairStatus $status
 * @property int $unit_id
 * @property string $unit_label
 * @property int $driver_id
 * @property int $reported_by
 * @property ProblemKind $problem_kind
 * @property string $problem_description
 * @property Carbon $detected_on
 * @property string|null $signed_form_path
 * @property string|null $inspection_notes
 * @property RepairUrgency|null $urgency
 * @property string|null $estimated_parts_or_service
 * @property string|null $estimated_cost
 * @property int|null $assessed_by
 * @property Carbon|null $assessed_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_notes
 * @property string|null $provider
 * @property string|null $work_performed
 * @property string|null $final_cost
 * @property Carbon|null $completed_on
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RepairRequest extends Model
{
    /** @use HasFactory<RepairRequestFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'unit_id' => 'integer',
            'driver_id' => 'integer',
            'reported_by' => 'integer',
            'status' => RepairStatus::class,
            'problem_kind' => ProblemKind::class,
            'urgency' => RepairUrgency::class,
            'detected_on' => 'date',
            'completed_on' => 'date',
            'assessed_at' => 'datetime',
            'decided_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'final_cost' => 'decimal:2',
        ];
    }

    public function formattedNumber(): string
    {
        return str_pad((string) $this->number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Restricts the query to the requests the user is allowed to see.
     *
     * @param  Builder<RepairRequest>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can(Permissions::REPAIRS_VIEW_ALL)) {
            return;
        }

        $query->where(function (Builder $query) use ($user) {
            $query->where('reported_by', $user->getKey())
                ->orWhereHas('driver', fn (Builder $driver) => $driver->where('user_id', $user->getKey()));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can(Permissions::REPAIRS_VIEW_ALL)
            || $this->reported_by === $user->getKey()
            || $this->driver?->user_id === $user->getKey();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<RepairRequestEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RepairRequestEvent::class)->latest('created_at')->latest('id');
    }
}
