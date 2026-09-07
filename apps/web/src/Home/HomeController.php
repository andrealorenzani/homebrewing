<?php

declare(strict_types=1);

namespace App\Home;

use App\Http\Request;
use App\Http\Response;
use App\Recipes\RecipeService;
use App\View\Renderer;

/**
 * `GET /` — the public homepage: a card-grid showcase of the N
 * most-recently-updated **public** recipes across all users (N is
 * config-driven, see Kernel::homeController()). Reachable by anonymous
 * visitors and logged-in users alike (registered behind
 * OptionalAuthMiddleware in Kernel, purely so the nav can still tell
 * Login/Register apart from Logout) — it never requires authentication
 * and never shows anything private.
 */
final class HomeController
{
    public function __construct(
        private readonly RecipeService $recipes,
        private readonly Renderer $renderer,
        private readonly int $recipeShowcaseCount,
    ) {
    }

    public function show(Request $request): Response
    {
        $recipes = $this->recipes->recentPublic($this->recipeShowcaseCount);

        $html = $this->renderer->renderWithLayout('pages/home', [
            'authUserId' => $request->getAttribute('auth_user_id'),
            'title' => 'Latest Recipes',
            'recipes' => $recipes,
        ]);

        return Response::html($html);
    }
}
