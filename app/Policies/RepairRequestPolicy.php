<?php

namespace App\Policies;

use App\Enums\RepairStatus;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\Permissions;

/**
 * Status changes are only performed through App\Services\RepairWorkflow,
 * so generic update/delete are always denied (keeps the audit trail intact).
 */
class RepairRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permissions::REPAIRS_REPORT, Permissions::REPAIRS_VIEW_ALL]);
    }

    public function view(User $user, RepairRequest $repairRequest): bool
    {
        return $repairRequest->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::REPAIRS_REPORT);
    }

    public function update(User $user, RepairRequest $repairRequest): bool
    {
        return false;
    }

    public function delete(User $user, RepairRequest $repairRequest): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function assess(User $user, RepairRequest $repairRequest): bool
    {
        return $user->can(Permissions::REPAIRS_ASSESS)
            && $repairRequest->status === RepairStatus::Reported;
    }

    public function decide(User $user, RepairRequest $repairRequest): bool
    {
        return $user->can(Permissions::REPAIRS_APPROVE)
            && $repairRequest->status === RepairStatus::PendingApproval;
    }

    public function startRepair(User $user, RepairRequest $repairRequest): bool
    {
        return $user->can(Permissions::REPAIRS_TRACK)
            && $repairRequest->status === RepairStatus::Approved;
    }

    public function complete(User $user, RepairRequest $repairRequest): bool
    {
        return $user->can(Permissions::REPAIRS_TRACK)
            && in_array($repairRequest->status, [RepairStatus::Approved, RepairStatus::InRepair], true);
    }

    public function cancel(User $user, RepairRequest $repairRequest): bool
    {
        return $user->can(Permissions::REPAIRS_ASSESS)
            && $repairRequest->status === RepairStatus::Reported;
    }

    public function attachSignedForm(User $user, RepairRequest $repairRequest): bool
    {
        return $user->can(Permissions::REPAIRS_ASSESS)
            && $repairRequest->status !== RepairStatus::Cancelled;
    }

    public function downloadSignedForm(User $user, RepairRequest $repairRequest): bool
    {
        return $repairRequest->signed_form_path !== null && $this->view($user, $repairRequest);
    }
}
