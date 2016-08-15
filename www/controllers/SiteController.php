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
    public $enableCsrfValidation = FALSE;

    public function behaviors()
    {
        return [
            'verbs' => [
                'class'   => VerbFilter::className(),
                'actions' => [
                    'delete' => ['post'],
                    'create' => ['post'],
                    'update' => ['post'],
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

        return $this->getItems();
    }

    /**
     * Creates a new Items model.
     *
     * @return mixed
     */
    public function actionCreate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $model = new Items;

        if ($model->load(Yii::$app->request->post()) && $model->save() && $this->getItems()) {

            return [
                'status' => 'success',
                'id'     => $model->id,
            ];
        }

        return [
            'status' => 'error',
            'errors' => $model->getFirstError(),
        ];
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

        $model = $this->findModel($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()  && $this->getItems()) {

            return [
                'status' => 'success',
                'id'     => $model->id,
            ];
        }

        return [
            'status' => 'error',
            'errors' => $model->getFirstError(),
        ];
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

        $this->findModel($id)->delete();
        $this->getItems();

        return [
            'status' => 'success',
        ];
    }

    /**
     * Finds the Items model based on its primary key value.
     *
     * @param integer $id
     * @return Items the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Items::findOne($id)) !== NULL) {
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
