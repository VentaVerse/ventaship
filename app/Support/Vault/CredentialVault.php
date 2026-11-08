<?php

namespace App\Support\Vault;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encrypts credentials and party data with VENTASHIP_VAULT_KEY (never APP_KEY),
 * so rotating APP_KEY never destroys stored data.
 */
class CredentialVault
{
    private ?Encrypter $encrypter = null;

    public function encrypt(string $plain): string
    {
        return $this->encrypter()->encryptString($plain);
    }

    public function decrypt(string $cipher): string
    {
        return $this->encrypter()->decryptString($cipher);
    }

    private function encrypter(): Encrypter
    {
        if ($this->encrypter) {
            return $this->encrypter;
        }

        $key = (string) config('ventaship.vault_key');
        if ($key === '') {
            throw new RuntimeException('VENTASHIP_VAULT_KEY missing');
        }

        $raw = base64_decode($key, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('VENTASHIP_VAULT_KEY must be base64 of 32 bytes');
        }

        return $this->encrypter = new Encrypter($raw, 'aes-256-gcm');
    }
}
