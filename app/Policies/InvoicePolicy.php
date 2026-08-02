<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $u): bool { return $u->can('invoice.viewAny'); }
    public function view(User $u, Invoice $i): bool { return $u->can('invoice.view'); }
    public function create(User $u): bool { return $u->can('invoice.create'); }
    public function update(User $u, Invoice $i): bool { return $u->can('invoice.update'); }
    public function delete(User $u, Invoice $i): bool { return $u->can('invoice.delete'); }
}
