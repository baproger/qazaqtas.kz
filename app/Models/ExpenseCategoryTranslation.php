<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategoryTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['expense_category_id', 'locale', 'name'];
}
