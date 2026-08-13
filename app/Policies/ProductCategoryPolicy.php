<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;

/**
 * Категории — часть каталога, поэтому права те же (модуль product.*).
 * Отдельная политика нужна, чтобы действие совпадало с правом: загрузка
 * снимка категории — это изменение категории, а не создание позиции.
 */
class ProductCategoryPolicy
{
    public function viewAny(User $user): bool { return $user->can('product.viewAny'); }

    public function view(User $user, ProductCategory $category): bool { return $user->can('product.view'); }

    public function create(User $user): bool { return $user->can('product.create'); }

    public function update(User $user, ProductCategory $category): bool { return $user->can('product.update'); }

    public function delete(User $user, ProductCategory $category): bool { return $user->can('product.delete'); }
}
