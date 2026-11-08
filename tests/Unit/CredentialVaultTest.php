<?php

namespace Tests\Unit;

use App\Support\Vault\CredentialVault;
use RuntimeException;
use Tests\TestCase;

class CredentialVaultTest extends TestCase
{
    public function test_round_trip(): void
    {
        $vault = new CredentialVault;

        $this->assertSame('s3cr3t', $vault->decrypt($vault->encrypt('s3cr3t')));
    }

    public function test_independent_of_app_key(): void
    {
        $vault = new CredentialVault;
        $cipher = $vault->encrypt('s3cr3t');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertSame('s3cr3t', (new CredentialVault)->decrypt($cipher));
    }

    public function test_missing_key_throws(): void
    {
        config(['ventaship.vault_key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VENTASHIP_VAULT_KEY missing');

        (new CredentialVault)->encrypt('x');
    }

    public function test_key_must_be_32_bytes(): void
    {
        foreach ([base64_encode(random_bytes(16)), 'not base64 !!!'] as $bad) {
            config(['ventaship.vault_key' => $bad]);

            try {
                (new CredentialVault)->encrypt('x');
                $this->fail('Expected RuntimeException for key: '.$bad);
            } catch (RuntimeException $e) {
                $this->assertSame('VENTASHIP_VAULT_KEY must be base64 of 32 bytes', $e->getMessage());
            }
        }
    }

    public function test_container_returns_singleton(): void
    {
        $this->assertSame(app(CredentialVault::class), app(CredentialVault::class));
    }
}
