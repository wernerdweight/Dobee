<?php

namespace WernerDweight\Dobee\LazyLoader;

use WernerDweight\Dobee\Provider\Provider;

class SingleLazyLoader {

    protected $provider;
    protected $entityName;
    protected $primaryKey;
    protected $options;
    protected $data;

    public function __construct(Provider $provider, $entityName, $primaryKey, $options = []){
        $this->provider = $provider;
        $this->entityName = $entityName;
        $this->primaryKey = $primaryKey;
        $this->options = $options;
        $this->data = null;
    }

    protected function loadData(){
        $this->data = $this->provider->fetchOne($this->entityName,$this->primaryKey,$this->options);
    }

    public function getData(){
        if($this->data === null){
            $this->loadData();
        }
        return $this->data;
    }

    public function __call($name,$args){
        if($this->data === null){
            $this->loadData();
        }
        if($this->data !== null){
            /// if method does NOT exist try getName
            if(!method_exists($this->data,$name)){
                if(method_exists($this->data,'get'.ucfirst($name))){
                    $name = 'get'.ucfirst($name);
                }
                /// if getter does NOT exist try isName
                else if(method_exists($this->data,'is'.ucfirst($name))){
                    $name = 'is'.ucfirst($name);
                }
                /// and finally hasName
                else if(method_exists($this->data,'has'.ucfirst($name))){
                    $name = 'has'.ucfirst($name);
                }
            }
            /// call desired method on data item
            return call_user_func_array(array($this->data,$name),$args);
        }
        else return null;
    }

}

?>
