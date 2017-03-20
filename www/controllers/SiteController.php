<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use app\models\Items;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;

class SiteController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return [
            'verbs' => [
                'class'   => VerbFilter::className(),
                'actions' => [
                    'delete' => ['delete'],
                    'create' => ['post'],
                    'update' => ['put'],
                ],
            ],
        ];
    }

    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    /**
     * @return string
     */
    public function actionIndex()
    {
        return $this->render('index');
    }

    /**
     * @return array
     */
    public function actionGet()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            return $this->getItems();
        } catch (\Exception $exception) {
            Yii::$app->response->setStatusCode(500);

            return [
                'error' => 'Unknown error',
            ];
        }
    }

    /**
     * Creates a new Items model.
     *
     * @return mixed
     */
    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $model = new Items;

            if ($model->load(Yii::$app->request->post()) && $model->save() && $this->getItems()) {

                Yii::$app->response->setStatusCode(201);

                return [
                    'id' => $model->id,
                ];
            }

            Yii::$app->response->setStatusCode(400);

            return [
                'error' => $model->getFirstErrors(),
            ];
        } catch (\Exception $exception) {
            Yii::$app->response->setStatusCode(500);

            return [
                'error' => 'Unknown error',
            ];
        }
    }

    /**
     * Updates an existing Items model.
     *.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdate($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $model = $this->findModel($id);

            if ($model->load(Yii::$app->request->post()) && $model->save() && $this->getItems()) {
                Yii::$app->response->setStatusCode(200);

                return [
                    'id' => $model->id,
                ];
            }

            Yii::$app->response->setStatusCode(400);

            return [
                'error' => $model->getFirstErrors(),
            ];
        } catch (NotFoundHttpException $exception) {
            Yii::$app->response->setStatusCode(404);

            return [
                'error' => $exception->getMessage(),
            ];
        } catch (\Exception $exception) {
            Yii::$app->response->setStatusCode(500);

            return [
                'error' => 'Unknown error',
            ];
        }
    }

    /**
     * Deletes an existing Items model.
     *
     * @param integer $id
     * @return array
     */
    public function actionDelete($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $this->findModel($id)->delete();
            $this->getItems();

            Yii::$app->response->setStatusCode(204);

            return [];
        } catch (NotFoundHttpException $exception) {
            Yii::$app->response->setStatusCode(404);

            return [
                'error' => $exception->getMessage(),
            ];
        } catch (\Exception $exception) {
            Yii::$app->response->setStatusCode(500);

            return [
                'error' => 'Unknown error',
            ];
        }
    }

    /**
     * Finds the Items model based on its primary key value.
     *
     * @param integer $id
     * @return Items
     * @throws NotFoundHttpException
     */
    protected function findModel($id)
    {
        if (($model = Items::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }

    /**
     * Return all items and save it in redis
     *
     * @return array
     */
    protected function getItems()
    {
        $items = [
            'rows' => Items::find()->orderBy('id')->asArray()->all(),
        ];

        Yii::$app->redis->set('data', json_encode($items));

        return $items;
    }
}
