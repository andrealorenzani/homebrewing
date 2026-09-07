<?php

declare(strict_types=1);

namespace App\Recipes\Exception;

/**
 * Thrown when a logged-in user attempts to edit/delete/toggle a recipe
 * they don't own. Unlike RecipeNotFoundException, this is a real
 * existing-recipe case (the user is already known to be authenticated),
 * so callers map this to a 403.
 */
final class RecipeAccessDeniedException extends \RuntimeException
{
}
