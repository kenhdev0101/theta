<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path is the operator cockpit's front door — it redirects there.
     */
    public function test_the_root_redirects_to_the_cockpit(): void
    {
        $this->get('/')->assertRedirect('/cockpit');
    }
}
