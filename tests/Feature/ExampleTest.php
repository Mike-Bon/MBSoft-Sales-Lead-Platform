<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_root_redirects_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
