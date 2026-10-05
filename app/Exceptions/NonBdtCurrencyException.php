<?php

namespace App\Exceptions;

use InvalidArgumentException;

/** Thrown whenever anything other than BDT reaches the financial domain. There is no FX and no other currency. */
class NonBdtCurrencyException extends InvalidArgumentException {}
