<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\CreateRecord;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

class CreatePost extends CreateRecord
{
    use HasAutosaveForCreate;

    protected static string $resource = PostResource::class;
}
