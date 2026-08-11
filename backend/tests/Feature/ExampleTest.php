<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The root URL is not a landing page - it is the CRM's front door.
     *
     * Was the stock Laravel welcome-page assertion until Phase 8 gave `/` a
     * job: send visitors to the dashboard, which in turn sends anyone without
     * a session to the login page.
     */
    #[Test]
    public function the_root_url_leads_to_the_crm(): void
    {
        $this->get('/')->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertRedirect('/login');
    }
}
