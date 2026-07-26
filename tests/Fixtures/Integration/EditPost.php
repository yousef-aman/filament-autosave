<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\EditRecord;
use YousefAman\FilamentAutosave\HasAutosave;

class EditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = PostResource::class;
}
