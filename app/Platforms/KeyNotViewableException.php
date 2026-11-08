<?php

namespace App\Platforms;

/** The key was issued before keys were stored encrypted, so only its hash exists. */
class KeyNotViewableException extends PlatformAppException {}
