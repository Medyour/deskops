<?php

test('the landing page describes the available foundation and dashboard', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('Chaque demande.')
        ->assertSee('Fondations')
        ->assertSee('Les indicateurs du tableau de bord sont disponibles.')
        ->assertSee(route('login'), false);
});

test('the Laravel health route is available', function () {
    $this->get('/up')->assertOk();
});
