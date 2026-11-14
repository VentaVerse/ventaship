<?php

namespace App\Shipments\Quotes;

use Throwable;

/**
 * Runs the jobs one after another in this process, with no time limit. For tests, where couriers are faked in this
 * process; the API uses ProcessQuoteRunner.
 */
final class InlineQuoteRunner implements QuoteRunner
{
    public function run(array $jobs, float $budgetSeconds): array
    {
        $results = [];
        foreach ($jobs as $key => $job) {
            try {
                $answer = AccountQuote::forJob($job);
                $results[$key] = $answer === null ? ['status' => 'failed'] : ['status' => 'done', 'answer' => $answer];
            } catch (Throwable) {
                $results[$key] = ['status' => 'failed'];
            }
        }

        return $results;
    }
}
