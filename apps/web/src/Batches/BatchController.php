<?php

declare(strict_types=1);

namespace App\Batches;

use App\Batches\Exception\BatchAccessDeniedException;
use App\Batches\Exception\BatchNotFoundException;
use App\Http\Csrf\CsrfTokenManager;
use App\Http\Request;
use App\Http\Response;
use App\Recipes\Exception\RecipeAccessDeniedException;
use App\Recipes\Exception\RecipeNotFoundException;
use App\View\Renderer;

/**
 * Batch + diary log entry CRUD + ownership + visibility-toggle routes
 * glue. Mirrors App\Recipes\RecipeController's conventions.
 *
 * `show()` is reachable by anonymous visitors and other logged-in users
 * (registered behind OptionalAuthMiddleware in Kernel); every other
 * action requires an authenticated user (RequireAuthMiddleware) and all
 * state-changing actions are CSRF-protected (CsrfMiddleware).
 */
final class BatchController
{
    public function __construct(
        private readonly BatchService $batches,
        private readonly CsrfTokenManager $csrf,
        private readonly Renderer $renderer,
    ) {
    }

    public function listOwn(Request $request): Response
    {
        $userId = (int) $request->getAttribute('auth_user_id');
        $batches = $this->batches->listOwn($userId);

        return $this->renderPage($request, 'pages/batches/index', [
            'title' => 'My Diaries',
            'batches' => $batches,
        ]);
    }

    public function showCreateForm(Request $request): Response
    {
        return $this->renderForm($request, '/diaries', 'Start a Batch Diary', 'Save Diary');
    }

    public function create(Request $request): Response
    {
        $userId = (int) $request->getAttribute('auth_user_id');
        $recipeId = (int) $request->getBodyParam('recipe_id', 0);
        $data = $this->parseBatchDataFromRequest($request);
        $errors = $this->validateBatch($data);

        if ($recipeId <= 0) {
            $errors['recipe_id'] = 'A recipe is required.';
        }

        if ($errors !== []) {
            return $this->renderForm($request, '/diaries', 'Start a Batch Diary', 'Save Diary', $errors, [...$data, 'recipe_id' => $recipeId], 422);
        }

        try {
            $id = $this->batches->create($userId, $recipeId, $data);
        } catch (RecipeNotFoundException) {
            return Response::notFound();
        } catch (RecipeAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect("/diaries/{$id}");
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $viewerIdRaw = $request->getAttribute('auth_user_id');
        $viewerId = $viewerIdRaw === null ? null : (int) $viewerIdRaw;

        try {
            $result = $this->batches->viewForUser($id, $viewerId);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        }

        $isOwner = $viewerId !== null && $viewerId === (int) $result['batch']['user_id'];

        $timeline = array_map(function (array $entry) use ($result): array {
            return [
                ...$entry,
                'dayNumber' => $this->batches->dayNumberFor(
                    $result['batch'],
                    new \DateTimeImmutable((string) $entry['entry_date']),
                ),
            ];
        }, $result['logEntries']);

        return $this->renderPage($request, 'pages/batches/show', [
            'title' => (string) ($result['batch']['label'] ?: 'Batch #' . $result['batch']['id']),
            'batch' => $result['batch'],
            'logEntries' => $timeline,
            'isOwner' => $isOwner,
        ]);
    }

    public function showEditForm(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $batch = $this->batches->getForEdit($id, $userId);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return $this->renderForm($request, "/diaries/{$id}/edit", 'Edit Diary', 'Update Diary', [], $batch, 200, true);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');
        $data = $this->parseBatchDataFromRequest($request);
        $errors = $this->validateBatch($data);

        if ($errors !== []) {
            return $this->renderForm($request, "/diaries/{$id}/edit", 'Edit Diary', 'Update Diary', $errors, $data, 422, true);
        }

        try {
            $this->batches->update($id, $userId, $data);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect("/diaries/{$id}");
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $this->batches->delete($id, $userId);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect('/diaries');
    }

    public function toggleVisibility(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $this->batches->toggleVisibility($id, $userId);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect("/diaries/{$id}");
    }

    public function showAddLogEntryForm(Request $request): Response
    {
        $batchId = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $this->batches->getForEdit($batchId, $userId);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return $this->renderLogEntryForm($request, "/diaries/{$batchId}/log-entries", 'Add Log Entry', 'Save Entry');
    }

    public function addLogEntry(Request $request): Response
    {
        $batchId = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');
        $data = $this->parseLogEntryDataFromRequest($request);
        $errors = $this->validateLogEntry($data);

        if ($errors !== []) {
            return $this->renderLogEntryForm($request, "/diaries/{$batchId}/log-entries", 'Add Log Entry', 'Save Entry', $errors, $data, 422);
        }

        try {
            $this->batches->addLogEntry($batchId, $userId, $data);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect("/diaries/{$batchId}");
    }

    public function showEditLogEntryForm(Request $request): Response
    {
        $batchId = (int) $request->getRouteParam('id');
        $entryId = (int) $request->getRouteParam('entryId');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $entry = $this->batches->getLogEntryForEdit($batchId, $entryId, $userId);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return $this->renderLogEntryForm(
            $request,
            "/diaries/{$batchId}/log-entries/{$entryId}/edit",
            'Edit Log Entry',
            'Update Entry',
            [],
            $entry,
        );
    }

    public function updateLogEntry(Request $request): Response
    {
        $batchId = (int) $request->getRouteParam('id');
        $entryId = (int) $request->getRouteParam('entryId');
        $userId = (int) $request->getAttribute('auth_user_id');
        $data = $this->parseLogEntryDataFromRequest($request);
        $errors = $this->validateLogEntry($data);

        if ($errors !== []) {
            return $this->renderLogEntryForm(
                $request,
                "/diaries/{$batchId}/log-entries/{$entryId}/edit",
                'Edit Log Entry',
                'Update Entry',
                $errors,
                $data,
                422,
            );
        }

        try {
            $this->batches->updateLogEntry($batchId, $entryId, $userId, $data);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect("/diaries/{$batchId}");
    }

    public function deleteLogEntry(Request $request): Response
    {
        $batchId = (int) $request->getRouteParam('id');
        $entryId = (int) $request->getRouteParam('entryId');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $this->batches->deleteLogEntry($batchId, $entryId, $userId);
        } catch (BatchNotFoundException) {
            return Response::notFound();
        } catch (BatchAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect("/diaries/{$batchId}");
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $batch
     */
    private function renderForm(
        Request $request,
        string $action,
        string $title,
        string $submitLabel,
        array $errors = [],
        array $batch = [],
        int $status = 200,
        bool $isEdit = false,
    ): Response {
        $userId = (int) $request->getAttribute('auth_user_id');

        return $this->renderPage($request, 'pages/batches/form', [
            'title' => $title,
            'formAction' => $action,
            'submitLabel' => $submitLabel,
            'errors' => $errors,
            'batch' => $batch,
            'statuses' => BatchService::STATUSES,
            'ownRecipes' => $isEdit ? [] : $this->batches->recipesOwnedBy($userId),
            'isEdit' => $isEdit,
        ], $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $entry
     */
    private function renderLogEntryForm(
        Request $request,
        string $action,
        string $title,
        string $submitLabel,
        array $errors = [],
        array $entry = [],
        int $status = 200,
    ): Response {
        return $this->renderPage($request, 'pages/batches/log_entry_form', [
            'title' => $title,
            'formAction' => $action,
            'submitLabel' => $submitLabel,
            'errors' => $errors,
            'entry' => $entry,
        ], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPage(Request $request, string $template, array $data, int $status = 200): Response
    {
        $html = $this->renderer->renderWithLayout($template, [
            'authUserId' => $request->getAttribute('auth_user_id'),
            'csrfField' => $this->csrf->hiddenField(),
            'errors' => [],
            ...$data,
        ]);

        return Response::html($html, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBatchDataFromRequest(Request $request): array
    {
        $status = (string) $request->getBodyParam('status', 'planning');

        if (!in_array($status, BatchService::STATUSES, true)) {
            $status = 'planning';
        }

        return [
            'label' => $this->nullableString($request->getBodyParam('label')),
            'status' => $status,
            'started_at' => trim((string) $request->getBodyParam('started_at', '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLogEntryDataFromRequest(Request $request): array
    {
        return [
            'entry_date' => trim((string) $request->getBodyParam('entry_date', '')),
            'note' => $this->nullableString($request->getBodyParam('note')),
            'specific_gravity' => $this->nullableNumeric($request->getBodyParam('specific_gravity')),
            'acidity_ph' => $this->nullableNumeric($request->getBodyParam('acidity_ph')),
            'temperature' => $this->nullableNumeric($request->getBodyParam('temperature')),
            'temperature_unit' => $this->nullableString($request->getBodyParam('temperature_unit')),
            'stage_vessel' => $this->nullableString($request->getBodyParam('stage_vessel')),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateBatch(array $data): array
    {
        $errors = [];

        if ($data['started_at'] === '') {
            $errors['started_at'] = 'Start date is required.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateLogEntry(array $data): array
    {
        $errors = [];

        if ($data['entry_date'] === '') {
            $errors['entry_date'] = 'Entry date is required.';
        }

        return $errors;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableNumeric(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
