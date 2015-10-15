<?php

namespace kalyabin\solr;

use Solarium\Client as SolrClient;
use yii\base\Component;

class Client extends Component
{
    public $options = [];

    public $solr;

    public function init()
    {
        $this->solr = new SolrClient($this->options);
    }

    public function __call($name, $params)
    {
        if(method_exists($this->solr, $name)){
            return call_user_func_array([$this->solr, $name], $params);
        }
        parent::call($name, $params); // We do this so we don't have to implement the exceptions ourselves
    }
}
