<?php

namespace WernerDweight\Dobee\Exception;

class ConfigurationFileNotFoundException extends \RuntimeException{

	public function __construct($message = null,$code = null,\Exception $previous = null){
		if(is_null($message)){
			$message = 'Configuration file was not found!';
		}
		parent::__construct($message,$code,$previous);
	}

}
