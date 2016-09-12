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

	protected function getEntityRelations($entity,$owningOnly = true,$prefixWithEntityName = false){
		$relations = array();

		if(isset($this->model[$entity]['relations'])){
			foreach ($this->model[$entity]['relations'] as $relatedEntity => $cardinality) {
				if($owningOnly !== true || in_array($cardinality, array('<<ONE_TO_ONE','MANY_TO_ONE','<<MANY_TO_MANY','SELF::ONE_TO_MANY','SELF::MANY_TO_ONE'))){
					$relations[($prefixWithEntityName === true ? $entity.':' : '').$relatedEntity] = $cardinality;
				}
			}
		}
		if(isset($this->model[$entity]['extends'])){
			$relations = array_merge($relations,$this->getEntityRelations($this->model[$entity]['extends'],$owningOnly,$prefixWithEntityName));
		}

		return $relations;
	}

	protected function getEntityJoinedColumnName($entity,$relatedEntity){
		if(isset($this->model[$entity]['relations'][$relatedEntity])){
			return $entity;
		}
		else if(isset($this->model[$entity]['extends'])){
			return $this->getEntityJoinedColumnName($this->model[$entity]['extends'],$relatedEntity);
		}
		else return null;
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

	protected function isSoftDeletable($entity){
		if(array_key_exists('softDeletable',$this->model[$entity])){
			return true;
		}
		else if(isset($this->model[$entity]['extends'])){
			return $this->isSoftDeletable($this->model[$entity]['extends']);
		}
		return false;
	}

	protected function isEntityLoggable($entity){
		if(array_key_exists('loggable',$this->model[$entity])){
			return true;
		}
		else if(isset($this->model[$entity]['extends'])){
			return $this->isEntityLoggable($this->model[$entity]['extends']);
		}
		return false;
	}

	protected function isEntityBlameable($entity){
		if(array_key_exists('blameable',$this->model[$entity])){
			return true;
		}
		else if(isset($this->model[$entity]['extends'])){
			return $this->isEntityBlameable($this->model[$entity]['extends']);
		}
		return false;
	}

	protected function getEntityBlameable($entity){
		if(isset($this->model[$entity]['blameable'])){
			return $this->model[$entity]['blameable'];
		}
		else if(isset($this->model[$entity]['extends'])){
			return $this->getEntityBlameable($this->model[$entity]['extends']);
		}
		return null;
	}

}
