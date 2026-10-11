<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a Free-tier user tries to analyze a meal photo after using up their
 * monthly allowance. Caught in MealController, which redirects to the pricing page
 * with an upgrade prompt.
 */
class PhotoLimitExceededException extends \RuntimeException
{
}
