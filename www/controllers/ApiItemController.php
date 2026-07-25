<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\ApiError;
use app\models\Items;
use app\services\ItemEvent;
use app\services\ItemEventPublisher;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The `/api/items` resource described by contracts/openapi.yaml.
 *
 * Differences from the 2017 SiteController, all deliberate:
 *
 *  - Resource paths (`/api/items`, `/api/items/{id}`) instead of `index.php?r=site/get`.
 *  - CSRF is ENFORCED. The old controller set `$enableCsrfValidation = false`, leaving
 *    every mutation open to cross-site forgery. It is disabled here only so that a failure
 *    can be answered with the contracted 403 instead of Yii's default 400.
 *  - No `catch (\Exception) { return 'Unknown error' }` around each action. Unexpected
 *    faults reach app\components\ErrorHandler, which returns a safe envelope AND logs the
 *    real cause; the old code discarded it.
 *  - Writes run in a transaction and publish their event only after the commit.
 */
final class ApiItemController extends Controller
{
    /**
     * Yii's built-in check throws BadRequestHttpException (400). The contract specifies
     * 403, so the same validateCsrfToken() call is made explicitly in beforeAction().
     */
    public $enableCsrfValidation = false;

    public function beforeAction($action): bool
    {
        $this->response->format = Response::FORMAT_JSON;

        if (!parent::beforeAction($action)) {
            return false;
        }

        $request = $this->request;

        if (!\in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if (!$request->validateCsrfToken()) {
                throw new ForbiddenHttpException('A valid X-CSRF-Token header is required for this request.');
            }

            if (\in_array($request->method, ['POST', 'PUT'], true)) {
                $this->requireJsonContentType();
            }
        }

        return true;
    }

    /**
     * GET /api/items -> 200 {"items": [...]} ordered by ascending id.
     *
     * @return array{items: list<array{id: int, name: string}>}
     */
    public function actionIndex(): array
    {
        $items = Items::find()->orderBy(['id' => SORT_ASC])->all();

        return [
            'items' => array_map(static fn (Items $item): array => $item->toApiRepresentation(), $items),
        ];
    }

    /**
     * POST /api/items -> 201 with the created representation.
     *
     * @return array{id: int, name: string}|array{code: string, message: string, details?: array<string, list<string>>}
     */
    public function actionCreate(): array
    {
        $name = $this->readName();

        if ($name instanceof ApiError) {
            return $this->fail(422, $name);
        }

        $item = new Items(['name' => $name]);

        if (!$item->validate()) {
            return $this->fail(422, ApiError::validation($item->validationDetails()));
        }

        $this->persist($item);

        $this->response->setStatusCode(201);
        $this->publish(ItemEvent::created($item));

        return $item->toApiRepresentation();
    }

    /**
     * PUT /api/items/{id} -> 200 with the updated representation.
     *
     * @return array{id: int, name: string}|array{code: string, message: string, details?: array<string, list<string>>}
     */
    public function actionUpdate(int $id): array
    {
        $item = $this->findItem($id);
        $name = $this->readName();

        if ($name instanceof ApiError) {
            return $this->fail(422, $name);
        }

        $item->name = $name;

        if (!$item->validate()) {
            return $this->fail(422, ApiError::validation($item->validationDetails()));
        }

        $this->persist($item);
        $this->publish(ItemEvent::updated($item));

        return $item->toApiRepresentation();
    }

    /**
     * DELETE /api/items/{id} -> 204 with an empty body.
     */
    public function actionDelete(int $id): void
    {
        $item = $this->findItem($id);

        $transaction = \Yii::$app->getDb()->beginTransaction();

        try {
            $item->delete();
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();

            throw $exception;
        }

        $this->publish(ItemEvent::deleted($id));

        // A 204 carries no body at all, so the JSON formatter must not run.
        $this->response->format = Response::FORMAT_RAW;
        $this->response->setStatusCode(204);
        $this->response->content = '';
    }

    /**
     * Writes inside a transaction so that a failure can never leave a half-applied change
     * that an event has already announced.
     */
    private function persist(Items $item): void
    {
        $transaction = \Yii::$app->getDb()->beginTransaction();

        try {
            if (!$item->save(false)) {
                throw new \RuntimeException('The item could not be saved.');
            }

            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();

            throw $exception;
        }
    }

    /**
     * Called only after a commit; never throws (FR-005, FR-007).
     */
    private function publish(ItemEvent $event): void
    {
        ItemEventPublisher::fromApplication()->publish($event);
    }

    private function findItem(int $id): Items
    {
        $item = Items::findOne(['id' => $id]);

        if ($item === null) {
            // Rendered by app\components\ErrorHandler as {"code":"not_found", ...} with no
            // exception class, path or SQL in it.
            throw new NotFoundHttpException('The requested item does not exist.');
        }

        return $item;
    }

    /**
     * Validates the request body against the OpenAPI `ItemInput` schema, which is a strict
     * object: `name` is required, must be a string, and no other property is allowed.
     *
     * @return string|ApiError the trimmed-on-validation name, or the error to return
     */
    private function readName(): string|ApiError
    {
        $body = $this->request->getBodyParams();

        if (!\is_array($body)) {
            return ApiError::validation(['body' => ['The request body must be a JSON object.']]);
        }

        $unknown = array_diff(array_keys($body), ['name']);

        if ($unknown !== []) {
            return ApiError::validation([
                'body' => array_map(
                    static fn (string|int $key): string => sprintf('Unknown property "%s".', (string) $key),
                    array_values($unknown),
                ),
            ]);
        }

        if (!\array_key_exists('name', $body)) {
            return ApiError::validation(['name' => ['Name cannot be blank.']]);
        }

        $name = $body['name'];

        if (!\is_string($name)) {
            return ApiError::validation(['name' => ['Name must be a string.']]);
        }

        return $name;
    }

    private function requireJsonContentType(): void
    {
        $contentType = strtolower(trim(explode(';', (string) $this->request->getContentType())[0]));

        if ($contentType !== 'application/json') {
            $this->response->setStatusCode(415);
            $this->response->data = (new ApiError(
                ApiError::UNSUPPORTED_MEDIA_TYPE,
                'This endpoint accepts application/json only.',
            ))->jsonSerialize();
            $this->response->send();

            \Yii::$app->end();
        }
    }

    /**
     * @return array{code: string, message: string, details?: array<string, list<string>>}
     */
    private function fail(int $status, ApiError $error): array
    {
        $this->response->setStatusCode($status);

        return $error->jsonSerialize();
    }
}
