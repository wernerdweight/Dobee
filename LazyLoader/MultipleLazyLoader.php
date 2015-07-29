<?php

namespace WernerDweight\Dobee\LazyLoader;

use WernerDweight\Dobee\Provider\Provider;
use WernerDweight\Dobee\Transformer\Transformer;

class MultipleLazyLoader {

	protected $provider;
	protected $entity;
	protected $entityName;
	protected $options;

	public function __construct(Provider $provider, $entity, $entityName, array $options){
		$this->provider = $provider;
		$this->entity = $entity;
		$this->entityName = $entityName;
		$this->options = $options;
	}

	public function loadData(){
        $data = $this->provider->fetch($this->entityName,$this->options);
        if(is_array($data) && count($data)){
            foreach ($data as $item) {
                $this->entity->{'set'.ucfirst(ucfirst(Transformer::pluralize($this->entityName)))}(array());
                $this->entity->{'add'.ucfirst($this->entityName)}($item);
            }
        }
        else{
        	$this->entity->{'set'.ucfirst(ucfirst(Transformer::pluralize($this->entityName)))}(null);
        }
    }

}

?>
