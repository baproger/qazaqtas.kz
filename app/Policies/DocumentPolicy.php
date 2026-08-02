<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $u): bool { return $u->can('document.viewAny'); }
    public function view(User $u, Document $d): bool { return $u->can('document.view') && $this->entityVisible($u, $d); }
    public function create(User $u): bool { return $u->can('document.create'); }
    public function update(User $u, Document $d): bool { return $u->can('document.update') && $this->entityVisible($u, $d); }
    public function delete(User $u, Document $d): bool { return $u->can('document.delete') && $this->entityVisible($u, $d); }

    /**
     * Документ доступен, только если доступна его сделка/заказ — так наследуются
     * и владение менеджера, и изоляция компаний (BAIA/ASU) из Deal/Project-политик.
     */
    private function entityVisible(User $u, Document $d): bool
    {
        $entity = $d->documentable;

        return $entity !== null && $u->can('view', $entity);
    }
}
