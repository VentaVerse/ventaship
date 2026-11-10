<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Admin extends Authenticatable
{
    protected $fillable = ['name', 'username', 'email', 'password', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'last_login_at' => 'datetime'];
    }

    /** Up to two capital letters for the avatar: first and last word of the name, else the email's first letter. */
    public function initials(): string
    {
        $words = array_values(array_filter(array_map(
            fn (string $w) => preg_replace('/[^\p{L}\p{N}]/u', '', $w) ?? '',
            preg_split('/[\s\-_.]+/u', (string) $this->name, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ), fn (string $w) => $w !== ''));
        $letters = match (count($words)) {
            0 => preg_replace('/[^\p{L}\p{N}]/u', '', mb_substr(trim((string) $this->email), 0, 1)) ?? '',
            1 => mb_substr($words[0], 0, 1),
            default => mb_substr($words[0], 0, 1).mb_substr($words[count($words) - 1], 0, 1),
        };

        return $letters === '' ? '?' : mb_strtoupper($letters);
    }
}
