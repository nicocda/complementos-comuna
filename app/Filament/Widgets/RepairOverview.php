<?php

namespace App\Filament\Widgets;

use App\Enums\RepairStatus;
use App\Filament\Resources\RepairRequests\RepairRequestResource;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\Permissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class RepairOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Reparaciones';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', RepairRequest::class) ?? false;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $query = fn () => RepairRequest::query()->visibleTo($user);
        $listUrl = RepairRequestResource::getUrl('index');

        $stats = [
            Stat::make('Esperando informe', $query()->where('status', RepairStatus::Reported)->count())
                ->color('info')
                ->url($listUrl),
            Stat::make('Pendientes de aprobación', $query()->where('status', RepairStatus::PendingApproval)->count())
                ->color('warning')
                ->url($listUrl),
            Stat::make('En curso', $query()->whereIn('status', [RepairStatus::Approved, RepairStatus::InRepair])->count())
                ->description('Autorizadas o en reparación')
                ->url($listUrl),
        ];

        if ($user->can(Permissions::REPAIRS_VIEW_ALL)) {
            $yearCost = (float) $query()
                ->where('status', RepairStatus::Completed)
                ->whereYear('completed_on', now()->year)
                ->sum('final_cost');

            $stats[] = Stat::make('Costo de reparaciones '.now()->year, Number::currency($yearCost, 'ARS', 'es_AR'))
                ->description('Reparaciones finalizadas en el año');
        }

        return $stats;
    }
}
