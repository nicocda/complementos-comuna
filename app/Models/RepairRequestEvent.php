<?php

namespace App\Models;

use App\Enums\RepairStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail of status changes.
 *
 * @property int $id
 * @property int $repair_request_id
 * @property int|null $user_id
 * @property RepairStatus|null $from_status
 * @property RepairStatus $to_status
 * @property string|null $comment
 * @property Carbon $created_at
 */
class RepairRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'from_status' => RepairStatus::class,
            'to_status' => RepairStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RepairRequest, $this>
     */
    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
