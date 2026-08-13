<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Перевод реализованного объекта: название, город, состав работ, описание. */
class SiteProjectTranslation extends Model
{
    protected $fillable = ['site_project_id', 'locale', 'title', 'city', 'products', 'description'];
}
