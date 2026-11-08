<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequest extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
