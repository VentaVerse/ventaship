<?php

use App\Models\ClientApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves client API keys to Sanctum tokens and drops request signing.
 *
 * Each active (not revoked) api_keys row becomes a personal_access_tokens row with the same SHA-256 hash. Our
 * key_hash was always hash('sha256', <the whole key>), and Sanctum looks up a token sent without '|' by exactly that
 * hash, so issued keys keep working unchanged. The vault ciphertext of the key is copied as is (the vault binds
 * nothing to the table or column). Revoked keys are not moved, so they can never authenticate.
 *
 * The api_keys id is kept as the token id when free, so api_requests.api_key_id carries over to token_id.
 *
 * Every step checks what is already done: on MySQL DDL is not transactional, and a rerun after a failure must
 * neither duplicate tokens nor fail on a column that already exists.
 *
 * down() restores the old tables and columns, empty: signing secrets are gone, so moved keys cannot be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'prefix')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('prefix', 8)->nullable()->index();
            });
        }
        if (! Schema::hasColumn('personal_access_tokens', 'token_encrypted')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->text('token_encrypted')->nullable(); // vault-encrypted; null for keys issued before keys were viewable
            });
        }

        $map = Schema::hasTable('api_keys') ? $this->moveKeys() : [];

        if (! Schema::hasColumn('api_requests', 'token_id')) {
            Schema::table('api_requests', function (Blueprint $table) {
                $table->foreignId('token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('api_requests', 'api_key_id')) {
            foreach ($map as $old => $new) {
                DB::table('api_requests')->where('api_key_id', $old)->update(['token_id' => $new]);
            }
            Schema::table('api_requests', function (Blueprint $table) {
                $table->dropForeign(['api_key_id']);
            });
            Schema::table('api_requests', function (Blueprint $table) {
                $table->dropColumn('api_key_id');
            });
        }

        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('request_signatures');
    }

    /** @return array<int, int> api_keys id => personal_access_tokens id, for every moved key */
    private function moveKeys(): array
    {
        $type = (new ClientApp)->getMorphClass();
        $map = [];

        DB::table('api_keys')->whereNull('revoked_at')->orderBy('id')->each(function (object $key) use ($type, &$map) {
            $existing = DB::table('personal_access_tokens')->where('token', $key->key_hash)->value('id');
            if ($existing !== null) {
                $map[(int) $key->id] = (int) $existing;

                return;
            }

            $row = [
                'tokenable_type' => $type,
                'tokenable_id' => $key->client_app_id,
                'name' => $key->name,
                'token' => $key->key_hash,
                'abilities' => '["*"]',
                'prefix' => $key->key_prefix,
                'token_encrypted' => $key->key_encrypted ?? null,
                'last_used_at' => $key->last_used_at,
                'expires_at' => $key->expires_at,
                'created_at' => $key->created_at,
                'updated_at' => $key->updated_at,
            ];

            if (DB::table('personal_access_tokens')->where('id', $key->id)->doesntExist()) {
                DB::table('personal_access_tokens')->insert(['id' => $key->id] + $row);
                $map[(int) $key->id] = (int) $key->id;
            } else {
                $map[(int) $key->id] = (int) DB::table('personal_access_tokens')->insertGetId($row);
            }
        });

        return $map;
    }

    public function down(): void
    {
        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_app_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('key_prefix', 8);
                $table->char('key_hash', 64)->unique();
                $table->text('key_encrypted')->nullable();
                $table->text('signing_secret');
                $table->json('scopes')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('request_signatures')) {
            Schema::create('request_signatures', function (Blueprint $table) {
                $table->id();
                $table->char('signature_hash', 64)->unique();
                $table->timestamp('created_at')->useCurrent();
                $table->index('created_at', 'request_signatures_created_idx');
            });
        }
        if (! Schema::hasColumn('api_requests', 'api_key_id')) {
            Schema::table('api_requests', function (Blueprint $table) {
                $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            });
        }
        if (Schema::hasColumn('api_requests', 'token_id')) {
            Schema::table('api_requests', function (Blueprint $table) {
                $table->dropForeign(['token_id']);
            });
            Schema::table('api_requests', function (Blueprint $table) {
                $table->dropColumn('token_id');
            });
        }
        foreach (['prefix', 'token_encrypted'] as $column) {
            if (Schema::hasColumn('personal_access_tokens', $column)) {
                Schema::table('personal_access_tokens', function (Blueprint $table) use ($column) {
                    if ($column === 'prefix') {
                        $table->dropIndex(['prefix']);
                    }
                    $table->dropColumn($column);
                });
            }
        }
    }
};
