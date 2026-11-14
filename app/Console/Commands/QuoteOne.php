<?php

namespace App\Console\Commands;

use App\Shipments\Quotes\AccountQuote;
use Illuminate\Console\Command;
use Throwable;

/**
 * One account's quote for POST /quotes with `couriers: "all"` (ProcessQuoteRunner): the job as JSON on stdin, the
 * answer as JSON on stdout. Not for people: it is hidden from the command list.
 */
class QuoteOne extends Command
{
    protected $signature = 'ventaship:quote-one';

    protected $description = 'Quote one courier account for the all-couriers quote (reads JSON on stdin)';

    protected $hidden = true;

    public function handle(): int
    {
        $job = json_decode((string) stream_get_contents(STDIN), true);
        if (! is_array($job)) {
            return self::FAILURE;
        }

        try {
            $answer = AccountQuote::forJob($job);
        } catch (Throwable) {
            return self::FAILURE;
        }
        if ($answer === null) {
            return self::FAILURE;
        }

        $this->output->write((string) json_encode($answer));

        return self::SUCCESS;
    }
}
