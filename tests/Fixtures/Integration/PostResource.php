<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('slug'),
            // Deliberately not a column: reaching the write would fail the schema.
            TextInput::make('vault_key')->password(),
            Group::make([
                TextInput::make('theme'),
                Select::make('mode')->options(['fast' => 'Fast', 'slow' => 'Slow']),
            ])->statePath('settings'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
