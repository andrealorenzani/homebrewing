<?php

declare(strict_types=1);

namespace App\Recipes\Exception;

/**
 * Thrown when a recipe id doesn't exist, or (deliberately, so the same
 * exception is used for both cases) when it exists but is private and the
 * requesting viewer is not its owner. Callers must map this to a 404, not
 * a 403, so a private recipe's existence is never revealed.
 */
final class RecipeNotFoundException extends \RuntimeException
{
}
