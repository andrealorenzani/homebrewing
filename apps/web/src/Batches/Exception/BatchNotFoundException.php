<?php

declare(strict_types=1);

namespace App\Batches\Exception;

/**
 * Thrown when a batch id doesn't exist, or (deliberately, so the same
 * exception is used for both cases) when it exists but is private and the
 * requesting viewer is not its owner. Also used for a log entry id that
 * doesn't exist or doesn't belong to the given batch. Callers must map
 * this to a 404, not a 403, so a private batch's existence is never
 * revealed. Mirrors App\Recipes\Exception\RecipeNotFoundException.
 */
final class BatchNotFoundException extends \RuntimeException
{
}
