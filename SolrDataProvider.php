<?php

namespace kalyabin\solr;

use yii\base\Model;
use yii\base\InvalidConfigException;
use yii\data\BaseDataProvider;
use yii\di\Instance;
use Solarium\Core\Query\Query as SolrQuery;
use kalyabin\solr\Client;

/**
 * This is the SolrDataProvider
 *
 * You use this to interact with widgets etc to provide them data.
 *
 * Basic usage of this class would be:
 *
 * $query = Yii::$app->solr->createSelect();
 * $query->setQuery('(alt_subject_mpath:' . $model->path . ' OR alt_subject_mpath:' . $model->path . '.*) AND live:1');
 *
 * new SolrDataProvider([
 *     'query' => $query,
 *     'modelClass' => 'common\models\SolrResult',
 *     'sort' => [
 *         'attributes' => [
 *             'title',
 *             'sales',
 *             'score'
 *         ]
 *     ]
 * ]);
 *
 */
class SolrDataProvider extends BaseDataProvider
{
    /**
     * @var SolrQuery the query that is used to fetch data models and [[totalCount]]
     * if it is not explicitly set.
     */
    public $query;

    /**
     * @var string|callable the column that is used as the key of the data models.
     * This can be either a column name, or a callable that returns the key value of a given data model.
     *
     * If this is not set, the following rules will be used to determine the keys of the data models:
     *
     * - If [[modelClass]] is an [[\yii\db\ActiveRecord]], the primary keys of [[\yii\db\ActiveRecord]] will be used.
     * - Otherwise, the keys of the [[models]] array will be used.
     *
     * @see getKeys()
     */
    public $key;

    /**
     * @var Connection|string the Solr connection object or the application component ID of the Solr connection.
     * If not set, the default solr connection will be used.
     */
    public $solr;

    /**
     * @var string|callable class name to build results objects. It's may be string (strong class name), or callbale.
     * Results class may be implemented by SolrDocumentInterface.
     *
     * Callable method returns strong class name by found document.
     *
     * Example to callable:
     *
     * ```php
     * function ($doc) {
     *     $fields = $doc->getFields();
     *     if ($fields['type'] == 'type1') {
     *         return \app\models\Type1::className();
     *     }
     *     else {
     *         return \app\models\Etc::className();
     *     }
     * }
     * ```
     * @see get()
     */
    public $modelClass;

    public function init()
    {
        parent::init();
        if (is_string($this->solr)) {
            $this->solr = Instance::ensure($this->solr, Connection::className());
        }elseif($this->solr === null){
            $this->solr = Instance::ensure('solr', Client::className());
        }
    }

    public function prepareModels()
    {
        if (!$this->query instanceof SolrQuery) {
            throw new InvalidConfigException('The "query" property must be an instance of a Solarium Query.');
        }
        if (($pagination = $this->getPagination()) !== false) {
            $pagination->totalCount = $this->getTotalCount();
            $this->query->setRows($pagination->getLimit())->setStart($pagination->getOffset());
        }
        if (($sort = $this->getSort()) !== false) {
            foreach($sort->getAttributeOrders() as $k => $order){
                $query = $this->query;
                $this->query->addSort($k, $order === SORT_ASC ? $query::SORT_ASC : $query::SORT_DESC);
            }
        }
        $resultset = $this->solr->select($this->query);
        $models = [];
        foreach($resultset as $result){
            $cname = $this->getModelClass($result);
            $models[] = $cname::populateFromSolr($result);
        }
        return $models;
    }

    public function prepareKeys($models)
    {
        $keys = [];
        if ($this->key !== null) {
            foreach ($models as $model) {
                if (is_string($this->key)) {
                    $keys[] = $model[$this->key];
                } else {
                    $keys[] = call_user_func($this->key, $model);
                }
            }
            return $keys;
        } else {

            if ($this->modelClass){
                /** @var \yii\db\ActiveRecord $class */
                $class = $this->getModelClass();
                $model = new $class;

                if($model instanceof \yii\db\ActiveRecord){

                    $pks = $class::primaryKey();
                    if (count($pks) === 1) {
                        $pk = $pks[0];
                        foreach ($models as $model) {
                            $keys[] = $model[$pk];
                        }
                    } else {
                        foreach ($models as $model) {
                            $kk = [];
                            foreach ($pks as $pk) {
                                $kk[$pk] = $model[$pk];
                            }
                            $keys[] = $kk;
                        }
                    }
                    return $keys;
                }
            }
            return array_keys($models);
        }
    }

    public function prepareTotalCount()
    {
        if (!$this->query instanceof SolrQuery) {
            throw new InvalidConfigException('The "query" property must be an instance of a Solarium Query.');
        }
        $query = clone $this->query;
        $resultset = $this->solr->select($query);

        return (int) $resultset->getNumFound();
    }

    public function setSort($value)
    {
        parent::setSort($value);
        if (($sort = $this->getSort()) !== false && empty($sort->attributes)) {
            /** @var Model $model */
            $modelClass = $this->getModelClass();
            $model = new $modelClass;
            if($model instanceof Model){
                foreach ($model->attributes() as $attribute) {
                    $sort->attributes[$attribute] = [
                    'asc' => [$attribute => SORT_ASC],
                    'desc' => [$attribute => SORT_DESC],
                    'label' => $model->getAttributeLabel($attribute),
                    ];
                }
            }
        }
    }

    /**
     * Returns class of model by document interface.
     *
     * @param mixed $doc solr document interface, may be null
     * @return string class name
     */
    public function getModelClass($doc = null)
    {
        if (is_callable($this->modelClass)) {
            return call_user_func_array($this->modelClass, [$doc]);
        }
        return $this->modelClass;
    }
}
