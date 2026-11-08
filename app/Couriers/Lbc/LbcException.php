<?php

namespace App\Couriers\Lbc;

use RuntimeException;

/** An LBC booking was refused before anything was sent. The message is safe to show staff. */
class LbcException extends RuntimeException
{
}
