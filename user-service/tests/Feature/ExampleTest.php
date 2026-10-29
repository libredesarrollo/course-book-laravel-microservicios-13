<?php

use App\Models\User;

it('sends anonymous visitors to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('shows the home page to a signed-in user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk();
});
