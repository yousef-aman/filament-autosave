<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

class OverridingEditPage extends FakeEditPage
{
    protected function autosaveDebounce(): ?int
    {
        return 3000;
    }

    /** @return array<string> */
    protected function autosaveExcept(): array
    {
        return ['secret_note'];
    }
}
