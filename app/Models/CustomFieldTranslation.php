<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomFieldTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['custom_field_id', 'locale', 'name'];
}
