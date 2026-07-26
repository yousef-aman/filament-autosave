<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'slug', 'settings'];

    protected $casts = ['settings' => 'array'];

    public $timestamps = false;
}
