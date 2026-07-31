<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('username is generated from name and post', function () {
    expect(User::uniqueUsername('Ramesh Kumar Sharma', 'Section Officer'))
        ->toBe('ramesh_kumar_sharma_sectio');
});

test('a colliding username gets a numeric suffix', function () {
    User::factory()->create(['username' => 'ramesh_kumar_sharma_sectio']);

    expect(User::uniqueUsername('Ramesh Kumar Sharma', 'Section Officer'))
        ->toBe('ramesh_kumar_sharma_sectio_2');
});
