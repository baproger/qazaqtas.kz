<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Корень сайта — публичная витрина: открывается без логина, даже когда
     * каталог ещё пустой. ERP начинается с /login.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertOk();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
