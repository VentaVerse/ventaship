<?php

namespace App\Couriers;

use RuntimeException;

/** A refused courier-account operation. The message is a short fact, safe to show to an admin. */
class CourierAccountException extends RuntimeException {}
