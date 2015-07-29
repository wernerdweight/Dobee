<?php

namespace WernerDweight\Dobee\Exception;

class InvalidPropertyTypeException extends \RuntimeException{

	public function __construct($message = null,$code = null,\Exception $previous = null){
		if(is_null($message)){
			$message = 'Invalid property type!';
		}
		parent::__construct($message,$code,$previous);
	}

}
