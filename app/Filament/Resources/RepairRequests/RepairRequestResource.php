<?php

namespace App\Filament\Resources\RepairRequests;

use App\Enums\RepairStatus;
use App\Filament\Resources\RepairRequests\Pages\CreateRepairRequest;
use App\Filament\Resources\RepairRequests\Pages\ListRepairRequests;
use App\Filament\Resources\RepairRequests\Pages\ViewRepairRequest;
use App\Filament\Resources\RepairRequests\Schemas\RepairRequestForm;
use App\Filament\Resources\RepairRequests\Schemas\RepairRequestInfolist;
use App\Filament\Resources\RepairRequests\Tables\RepairRequestsTable;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class RepairRequestResource extends Resource
{
    protected static ?string $model = RepairRequest::class;

    protected static ?string $slug = 'reparaciones';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Reparaciones';

    protected static ?string $navigationLabel = 'Solicitudes';

    protected static ?string $modelLabel = 'solicitud de reparación';

    protected static ?string $pluralModelLabel = 'solicitudes de reparación';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return RepairRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RepairRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RepairRequestsTable::configure($table);
    }

    /**
     * Every query (list, view, route binding) only sees what the user is allowed to see.
     *
     * @return Builder<RepairRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = RepairRequest::query()->with(['driver', 'unit']);
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->visibleTo($user);
    }

    /**
     * Badge with the number of requests waiting for this user's action.
     */
    public static function getNavigationBadge(): ?string
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $statuses = [];

        if ($user->can(Permissions::REPAIRS_ASSESS)) {
            $statuses[] = RepairStatus::Reported;
        }

        if ($user->can(Permissions::REPAIRS_APPROVE)) {
            $statuses[] = RepairStatus::PendingApproval;
        }

        if ($statuses === []) {
            return null;
        }

        $count = static::getEloquentQuery()->whereIn('status', $statuses)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepairRequests::route('/'),
            'create' => CreateRepairRequest::route('/create'),
            'view' => ViewRepairRequest::route('/{record}'),
        ];
    }
}
