<?php

namespace Tests\Unit;

use App\Enums\RepairStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepairStatusTest extends TestCase
{
    /**
     * @return array<string, array{RepairStatus, RepairStatus, bool}>
     */
    public static function transitions(): array
    {
        return [
            'report -> pending approval' => [RepairStatus::Reported, RepairStatus::PendingApproval, true],
            'report -> cancelled' => [RepairStatus::Reported, RepairStatus::Cancelled, true],
            'report -> approved (skips report)' => [RepairStatus::Reported, RepairStatus::Approved, false],
            'pending -> approved' => [RepairStatus::PendingApproval, RepairStatus::Approved, true],
            'pending -> rejected' => [RepairStatus::PendingApproval, RepairStatus::Rejected, true],
            'pending -> back to reported' => [RepairStatus::PendingApproval, RepairStatus::Reported, true],
            'pending -> completed (skips approval)' => [RepairStatus::PendingApproval, RepairStatus::Completed, false],
            'approved -> in repair' => [RepairStatus::Approved, RepairStatus::InRepair, true],
            'approved -> completed' => [RepairStatus::Approved, RepairStatus::Completed, true],
            'in repair -> completed' => [RepairStatus::InRepair, RepairStatus::Completed, true],
            'rejected is final' => [RepairStatus::Rejected, RepairStatus::Approved, false],
            'completed is final' => [RepairStatus::Completed, RepairStatus::InRepair, false],
            'cancelled is final' => [RepairStatus::Cancelled, RepairStatus::Reported, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_transition_rules(RepairStatus $from, RepairStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_final_states_have_no_transitions(): void
    {
        foreach (RepairStatus::cases() as $status) {
            $this->assertSame($status->isFinal(), $status->allowedTransitions() === [], $status->value);
        }
    }
}
