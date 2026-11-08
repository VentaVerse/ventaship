<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourierAccountResource;
use App\Models\CourierAccount;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CourierAccountsController extends Controller
{
    /** GET /courier-accounts */
    public function index(): AnonymousResourceCollection
    {
        $accounts = CourierAccount::query()->orderBy('courier')->orderByDesc('is_default')->orderBy('label')->orderBy('id')->get();

        return CourierAccountResource::collection($accounts);
    }

    /** GET /courier-accounts/{id} */
    public function show(string $account): CourierAccountResource
    {
        return new CourierAccountResource(CourierAccount::query()->where('public_id', $account)->firstOrFail());
    }
}
