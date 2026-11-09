<?php

use App\Shipments\RecipientTokens;
use App\Support\Vault\CredentialVault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Blind index of the recipient's name words (App\Shipments\RecipientTokens), so the shipments list can search by name. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipments', 'recipient_tokens')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->text('recipient_tokens')->nullable();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'recipient_tokens')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('recipient_tokens');
            });
        }
    }

    /** Fills the tokens of every row holding a recipient, 200 at a time; a row that cannot be decrypted is skipped. */
    public function backfill(): void
    {
        $vault = app(CredentialVault::class);

        DB::table('shipments')->whereNotNull('recipient')->whereNull('recipient_tokens')
            ->select(['id', 'public_id', 'recipient'])
            ->chunkById(200, function ($rows) use ($vault) {
                foreach ($rows as $row) {
                    try {
                        $recipient = json_decode($vault->decrypt((string) $row->recipient), true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable $e) {
                        Log::warning('Recipient tokens: could not read a recipient', ['shipment' => $row->public_id, 'exception' => $e::class]);

                        continue;
                    }

                    DB::table('shipments')->where('id', $row->id)
                        ->update(['recipient_tokens' => RecipientTokens::forRecipient(is_array($recipient) ? $recipient : null)]);
                }
            });
    }
};
