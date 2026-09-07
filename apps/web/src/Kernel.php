<?php

declare(strict_types=1);

namespace App;

use App\Auth\AuthController;
use App\Auth\AuthService;
use App\Auth\UserRepository;
use App\Batches\BatchController;
use App\Batches\BatchLogEntryRepository;
use App\Batches\BatchRepository;
use App\Batches\BatchService;
use App\Config\Config;
use App\Db\DbInterface;
use App\Db\PdoDb;
use App\Home\HomeController;
use App\Http\Csrf\CsrfTokenManager;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\OptionalAuthMiddleware;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session\PhpSession;
use App\Http\Session\SessionInterface;
use App\Recipes\RecipeController;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;
use App\View\Renderer;

/**
 * Wires the application's routes onto a Router and dispatches requests.
 * Kept intentionally small: it's the thing both the real front controller
 * (public/index.php) and tests use to exercise the app without going
 * through a real webserver.
 *
 * Session and Db are resolved lazily (only on first actual use, i.e. when
 * a route that needs them is dispatched) rather than eagerly in the
 * constructor. This matters for tests: constructing a Kernel with no
 * session/db supplied (e.g. `new Kernel(Config::fromArray([]))` for a
 * plain /healthz check) must not start a real PHP session or open a real
 * database connection.
 */
final class Kernel
{
    private readonly Router $router;
    private readonly Renderer $renderer;
    private ?SessionInterface $session;
    private ?DbInterface $db;
    private ?CsrfTokenManager $csrf = null;

    public function __construct(
        private readonly Config $config,
        ?SessionInterface $session = null,
        ?DbInterface $db = null,
    ) {
        $this->session = $session;
        $this->db = $db;
        $this->renderer = new Renderer(dirname(__DIR__) . '/templates');
        $this->router = new Router();
        $this->registerRoutes($this->router);
    }

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    private function session(): SessionInterface
    {
        if ($this->session === null) {
            $this->session = new PhpSession();
        }

        return $this->session;
    }

    private function db(): DbInterface
    {
        if ($this->db === null) {
            $this->db = PdoDb::fromConfig($this->config);
        }

        return $this->db;
    }

    private function csrf(): CsrfTokenManager
    {
        if ($this->csrf === null) {
            $this->csrf = new CsrfTokenManager($this->session());
        }

        return $this->csrf;
    }

    private function authController(): AuthController
    {
        return new AuthController(
            new AuthService(new UserRepository($this->db()), $this->session()),
            $this->csrf(),
            $this->renderer,
        );
    }

    private function homeController(): HomeController
    {
        return new HomeController(
            new RecipeService(
                $this->db(),
                new RecipeRepository($this->db()),
                new RecipeIngredientRepository($this->db()),
            ),
            $this->renderer,
            (int) $this->config->get('HOMEPAGE_RECIPE_COUNT', 6),
        );
    }

    private function recipeController(): RecipeController
    {
        return new RecipeController(
            new RecipeService(
                $this->db(),
                new RecipeRepository($this->db()),
                new RecipeIngredientRepository($this->db()),
            ),
            $this->csrf(),
            $this->renderer,
        );
    }

    private function batchController(): BatchController
    {
        return new BatchController(
            new BatchService(
                new BatchRepository($this->db()),
                new BatchLogEntryRepository($this->db()),
                new RecipeRepository($this->db()),
            ),
            $this->csrf(),
            $this->renderer,
        );
    }

    /**
     * Wraps middleware construction in a closure so it is only resolved
     * (and, transitively, only touches session()/db()) when a route
     * using it is actually dispatched — not at route-registration time,
     * which runs for every route on every Kernel construction.
     */
    private function requireAuthMw(): callable
    {
        return fn (Request $r, callable $next): Response => (new RequireAuthMiddleware($this->session()))->handle($r, $next);
    }

    private function optionalAuthMw(): callable
    {
        return fn (Request $r, callable $next): Response => (new OptionalAuthMiddleware($this->session()))->handle($r, $next);
    }

    private function csrfMw(): callable
    {
        return fn (Request $r, callable $next): Response => (new CsrfMiddleware($this->csrf()))->handle($r, $next);
    }

    private function registerRoutes(Router $router): void
    {
        $router->get('/healthz', static function (Request $request): Response {
            return Response::json(['status' => 'ok'], 200);
        });

        $router->get('/', fn (Request $r): Response => $this->homeController()->show($r), [$this->optionalAuthMw()]);

        $router->get('/register', fn (Request $r): Response => $this->authController()->showRegisterForm($r));
        $router->post('/register', fn (Request $r): Response => $this->authController()->register($r), [$this->csrfMw()]);
        $router->get('/login', fn (Request $r): Response => $this->authController()->showLoginForm($r));
        $router->post('/login', fn (Request $r): Response => $this->authController()->login($r), [$this->csrfMw()]);
        $router->post('/logout', fn (Request $r): Response => $this->authController()->logout($r), [$this->csrfMw()]);

        $router->get('/recipes', fn (Request $r): Response => $this->recipeController()->listOwn($r), [$this->requireAuthMw()]);
        $router->get('/recipes/new', fn (Request $r): Response => $this->recipeController()->showCreateForm($r), [$this->requireAuthMw()]);
        $router->post('/recipes', fn (Request $r): Response => $this->recipeController()->create($r), [$this->requireAuthMw(), $this->csrfMw()]);
        $router->get('/recipes/{id}', fn (Request $r): Response => $this->recipeController()->show($r), [$this->optionalAuthMw()]);
        $router->get('/recipes/{id}/edit', fn (Request $r): Response => $this->recipeController()->showEditForm($r), [$this->requireAuthMw()]);
        $router->post('/recipes/{id}/edit', fn (Request $r): Response => $this->recipeController()->update($r), [$this->requireAuthMw(), $this->csrfMw()]);
        $router->post('/recipes/{id}/delete', fn (Request $r): Response => $this->recipeController()->delete($r), [$this->requireAuthMw(), $this->csrfMw()]);
        $router->post(
            '/recipes/{id}/toggle-visibility',
            fn (Request $r): Response => $this->recipeController()->toggleVisibility($r),
            [$this->requireAuthMw(), $this->csrfMw()],
        );

        $router->get('/diaries', fn (Request $r): Response => $this->batchController()->listOwn($r), [$this->requireAuthMw()]);
        $router->get('/diaries/new', fn (Request $r): Response => $this->batchController()->showCreateForm($r), [$this->requireAuthMw()]);
        $router->post('/diaries', fn (Request $r): Response => $this->batchController()->create($r), [$this->requireAuthMw(), $this->csrfMw()]);
        $router->get('/diaries/{id}', fn (Request $r): Response => $this->batchController()->show($r), [$this->optionalAuthMw()]);
        $router->get('/diaries/{id}/edit', fn (Request $r): Response => $this->batchController()->showEditForm($r), [$this->requireAuthMw()]);
        $router->post('/diaries/{id}/edit', fn (Request $r): Response => $this->batchController()->update($r), [$this->requireAuthMw(), $this->csrfMw()]);
        $router->post('/diaries/{id}/delete', fn (Request $r): Response => $this->batchController()->delete($r), [$this->requireAuthMw(), $this->csrfMw()]);
        $router->post(
            '/diaries/{id}/toggle-visibility',
            fn (Request $r): Response => $this->batchController()->toggleVisibility($r),
            [$this->requireAuthMw(), $this->csrfMw()],
        );
        $router->get(
            '/diaries/{id}/log-entries/new',
            fn (Request $r): Response => $this->batchController()->showAddLogEntryForm($r),
            [$this->requireAuthMw()],
        );
        $router->post(
            '/diaries/{id}/log-entries',
            fn (Request $r): Response => $this->batchController()->addLogEntry($r),
            [$this->requireAuthMw(), $this->csrfMw()],
        );
        $router->get(
            '/diaries/{id}/log-entries/{entryId}/edit',
            fn (Request $r): Response => $this->batchController()->showEditLogEntryForm($r),
            [$this->requireAuthMw()],
        );
        $router->post(
            '/diaries/{id}/log-entries/{entryId}/edit',
            fn (Request $r): Response => $this->batchController()->updateLogEntry($r),
            [$this->requireAuthMw(), $this->csrfMw()],
        );
        $router->post(
            '/diaries/{id}/log-entries/{entryId}/delete',
            fn (Request $r): Response => $this->batchController()->deleteLogEntry($r),
            [$this->requireAuthMw(), $this->csrfMw()],
        );
    }
}
