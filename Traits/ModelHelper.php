<?php

namespace WernerDweight\Dobee\Traits;

trait ModelHelper {

	protected function getPrimaryKeyForEntity($entity){
		if(isset($this->model[$entity]['extends'])){
			return $this->getPrimaryKeyForEntity($this->model[$entity]['extends']);
		}
		return $this->model[$entity]['primary'];
	}

	protected function hasProperty($entity,$property){
		if(isset($this->model[$entity]['properties'][$property])){
			return true;
		}
		else if(isset($this->model[$entity]['extends'])){
			return $this->hasProperty($this->model[$entity]['extends'],$property);
		}
		else{
			return false;
		}
	}

	protected function getEntityName($entity){
		$class = get_class($entity);
		
		if(preg_match('/\\\/',$class)){
			$class = preg_replace('/^.*\\\([a-zA-Z0-9]+)$/','$1',$class);
		}

		return lcfirst($class);
	}

	protected function getEntityProperties($entity){
		$properties = array();

		if(isset($this->model[$entity]['properties'])){
			foreach ($this->model[$entity]['properties'] as $property => $options) {
				$properties[$property] = $property;
			}
		}
		if(isset($this->model[$entity]['extends'])){
			$properties = array_merge($properties,$this->getEntityProperties($this->model[$entity]['extends']));
		}

		return $properties;
	}

	protected function getEntityRelations($entity){
		$relations = array();

		if(isset($this->model[$entity]['relations'])){
			foreach ($this->model[$entity]['relations'] as $relatedEntity => $cardinality) {
				if(in_array($cardinality, array('<<ONE_TO_ONE','MANY_TO_ONE','<<MANY_TO_MANY'))){
					$relations[$relatedEntity] = $cardinality;
				}
			}
		}
		if(isset($this->model[$entity]['extends'])){
			$relations = array_merge($relations,$this->getEntityRelations($this->model[$entity]['extends']));
		}

		return $relations;
	}

	protected function getDefaultOrderForEntity($entity,$returnProperty = true){
		if(isset($this->model[$entity]['defaultOrderBy'])){
			foreach ($this->model[$entity]['defaultOrderBy'] as $property => $direction) {
				return $returnProperty === true ? $property : $direction;
			}
		}
		else if(isset($this->model[$entity]['extends'])){
			return $this->getDefaultOrderForEntity($this->model[$entity]['extends'],$returnProperty);
		}
		return $returnProperty === true ? $this->getPrimaryKeyForEntity($entity) : 'asc';
	}

}
