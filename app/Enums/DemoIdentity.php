<?php

namespace App\Enums;

enum DemoIdentity: string
{
    case Administrator = 'administrator';
    case Alex = 'alex';
    case Sam = 'sam';

    public function role(): UserRole
    {
        return $this === self::Administrator ? UserRole::Admin : UserRole::Agent;
    }

    public function email(): string
    {
        return match ($this) {
            self::Administrator => 'admin@deskops.example',
            self::Alex => 'alex@deskops.example',
            self::Sam => 'sam@deskops.example',
        };
    }

    public function name(): string
    {
        return match ($this) {
            self::Administrator => 'Camille Martin',
            self::Alex => 'Alex Bernard',
            self::Sam => 'Sam Dubois',
        };
    }
}
