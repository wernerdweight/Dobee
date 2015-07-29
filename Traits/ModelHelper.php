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

}
