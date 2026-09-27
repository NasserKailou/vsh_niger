<?php

declare(strict_types=1);

return [
    'validation' => [
        'required' => 'Ce champ est obligatoire.',
        'not_null' => 'Ce champ ne peut pas être vide.',
        'string' => 'Ce champ doit être un texte.',
        'integer' => 'Ce champ doit être un nombre entier.',
        'numeric' => 'Ce champ doit être un nombre.',
        'boolean' => 'Ce champ doit valoir vrai ou faux.',
        'array' => 'Ce champ doit être une liste.',
        'email' => 'Adresse e-mail invalide.',
        'phone' => 'Numéro de téléphone invalide.',
        'uuid' => 'Identifiant invalide.',
        'date' => 'Date invalide (format attendu : AAAA-MM-JJ).',
        'datetime' => 'Date et heure invalides.',
        'in' => 'Valeur non autorisée.',
        'regex' => 'Format invalide.',
        'latitude' => 'Latitude invalide.',
        'longitude' => 'Longitude invalide.',
        'min.string' => 'Au moins :min caractères.',
        'min.numeric' => 'La valeur doit être supérieure ou égale à :min.',
        'min.array' => 'Au moins :min élément(s).',
        'max.string' => 'Au plus :max caractères.',
        'max.numeric' => 'La valeur doit être inférieure ou égale à :max.',
        'max.array' => 'Au plus :max élément(s).',
    ],
];
