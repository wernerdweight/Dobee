<?php

namespace WernerDweight\Dobee\Exception;

class InvalidConfigurationException extends \RuntimeException{

	public function __construct($message = null,$code = null,\Exception $previous = null){
		if(is_null($message)){
			$message = 'The configuration for Dobee is invalid!';
		}
		parent::__construct($message,$code,$previous);
	}

}
