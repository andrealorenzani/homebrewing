<?php

declare(strict_types=1);

namespace App\Batches\Exception;

/**
 * Thrown when a logged-in user attempts to edit/delete/toggle a batch (or
 * add/edit/delete a log entry on a batch) they don't own. Unlike
 * BatchNotFoundException, this is a real existing-batch case (the user is
 * already known to be authenticated), so callers map this to a 403.
 * Mirrors App\Recipes\Exception\RecipeAccessDeniedException.
 */
final class BatchAccessDeniedException extends \RuntimeException
{
}
