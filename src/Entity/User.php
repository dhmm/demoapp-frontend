<?php

namespace App\Entity;

/**
 * User entity (DTO).
 *
 * Represents the authenticated user's profile as returned by the backend API.
 * Stored in the Symfony session via SessionAuthService.
 */
class User
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $email,
        public readonly string  $role,
        public readonly string  $status,
        public readonly ?string $avatarUrl,
        public readonly bool    $twoFactorEnabled,
        public readonly ?string $emailVerifiedAt,
        public readonly string  $createdAt,
    ) {}

    /**
     * Construct a User from a raw API response array.
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id:               (int)  ($data['id'] ?? 0),
            name:                     $data['name'] ?? '',
            email:                    $data['email'] ?? '',
            role:                     $data['role'] ?? 'customer',
            status:                   $data['status'] ?? 'active',
            avatarUrl:                $data['avatar_url'] ?? null,
            twoFactorEnabled: (bool) ($data['two_factor_enabled'] ?? false),
            emailVerifiedAt:          $data['email_verified_at'] ?? null,
            createdAt:                $data['created_at'] ?? '',
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isManager(): bool
    {
        return in_array($this->role, ['admin', 'manager'], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isEmailVerified(): bool
    {
        return ! empty($this->emailVerifiedAt);
    }

    public function getInitials(): string
    {
        $parts = explode(' ', trim($this->name));
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }
        return strtoupper(mb_substr($this->name, 0, 2));
    }

    public function getDisplayName(): string
    {
        return $this->name ?: $this->email;
    }
}
