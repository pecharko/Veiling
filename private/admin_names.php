<?php
declare(strict_types=1);

/** Normalize a human-readable account name without changing the login identity. */
function admin_validated_name(string $input): string {
    $name = trim($input);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120 ||
        preg_match('/[\x00-\x1F\x7F]/', $name)) {
        throw new InvalidArgumentException('Vul een naam in van maximaal 120 tekens, zonder regelafbrekingen.');
    }
    return preg_replace('/ {2,}/u', ' ', $name) ?? $name;
}

/** Historic accounts have no name until the administrator completes the profile. */
function admin_name_label(?string $name): string {
    $name = trim((string)$name);
    return $name !== '' ? $name : 'Naam nog niet ingevuld';
}
