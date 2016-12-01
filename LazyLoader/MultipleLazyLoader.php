<?php

namespace WernerDweight\Dobee\LazyLoader;

use WernerDweight\Dobee\Provider\Provider;
use WernerDweight\Dobee\Transformer\Transformer;

class MultipleLazyLoader {

	protected $provider;
	protected $entity;
	protected $entityName;
	protected $options;

	public function __construct(Provider $provider, $entity, $entityName, array $options, array $extras = null){
		$this->provider = $provider;
		$this->entity = $entity;
		$this->entityName = $entityName;
		$this->options = $options;
		$this->extras = null !== $extras ? $extras : [
			'prefix' => '',
		];
	}

	public function loadData(){
        $data = $this->provider->fetch($this->entityName,$this->options);
        if(is_array($data) && count($data)){
            $this->entity->{'set'.$this->extras['prefix'].ucfirst(ucfirst(Transformer::pluralize($this->entityName)))}(array());
            foreach ($data as $item) {
                $this->entity->{'add'.$this->extras['prefix'].ucfirst($this->entityName)}($item);
            }
        }
        else{
			$this->entity->{'set'.$this->extras['prefix'].ucfirst(ucfirst(Transformer::pluralize($this->entityName)))}(array());
        }
    }

}

?>
