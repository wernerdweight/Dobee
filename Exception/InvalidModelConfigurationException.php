<?php

namespace WernerDweight\Dobee\Exception;

class InvalidModelConfigurationException extends \RuntimeException{

	public function __construct($message = null,$code = null,\Exception $previous = null){
		if(is_null($message)){
			$message = 'The model for Dobee is invalid!';
		}
		parent::__construct($message,$code,$previous);
	}

}
