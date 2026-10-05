<?php

namespace App\Exceptions;

use RuntimeException;

/** Safe-to-display business rule failure (never carries technical detail). */
class FinancialException extends RuntimeException {}
