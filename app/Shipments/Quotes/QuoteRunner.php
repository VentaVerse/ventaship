<?php

namespace App\Shipments\Quotes;

/**
 * Runs one quote job per account at the same time and returns what came back within the budget. A job is
 * `{account_id, data}`; its answer is AccountQuote::quote()'s array.
 */
interface QuoteRunner
{
    /**
     * @param  array<string, array{account_id: string, data: array<string, mixed>}>  $jobs  keyed by account public id
     * @return array<string, array{status: 'done', answer: array<string, mixed>}|array{status: 'timeout'}|array{status: 'failed'}>
     */
    public function run(array $jobs, float $budgetSeconds): array;
}
