<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Support\Permissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre y apellido')
                    ->required()
                    ->maxLength(150),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->revealable()
                    ->rule(Password::default())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit'
                        ? 'Dejar vacío para mantener la contraseña actual.'
                        : 'Mínimo 12 caracteres, con letras y números.'),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->helperText('Un usuario inactivo no puede ingresar ni recibe notificaciones.')
                    ->default(true)
                    // Prevents an admin from locking themselves out.
                    ->disabled(fn (?User $record) => $record !== null && $record->is(auth()->user())),
                CheckboxList::make('roles')
                    ->label('Roles')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Role $record) => Permissions::roleLabels()[$record->name] ?? $record->name)
                    // Prevents an admin from removing their own admin role.
                    ->disabled(fn (?User $record) => $record !== null && $record->is(auth()->user()))
                    ->required()
                    ->columnSpanFull(),
            ]);
    }
}
