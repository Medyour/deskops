<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'string' => 'Le champ :attribute doit être du texte.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'min' => ['string' => 'Le champ :attribute doit contenir au moins :min caractères.'],
    'password' => [
        'mixed' => 'Le champ :attribute doit contenir des majuscules et des minuscules.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
    ],
    'attributes' => [
        'email' => 'adresse e-mail',
        'password' => 'mot de passe',
    ],
];
