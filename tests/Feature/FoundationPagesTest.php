<?php

test('the landing page describes the available foundation and future scope', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('Chaque demande.')
        ->assertSee('Fondations')
        ->assertSee('À venir')
        ->assertSee(route('login'), false);
});

test('the Laravel health route is available', function () {
    $this->get('/up')->assertOk();
});
