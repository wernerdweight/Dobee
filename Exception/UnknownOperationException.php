<?php

namespace WernerDweight\Dobee\Exception;

class UnknownOperationException extends \RuntimeException{

	public function __construct($message = null,$code = null,\Exception $previous = null){
		if(is_null($message)){
			$message = 'Unsupported operation!';
		}
		parent::__construct($message,$code,$previous);
	}

}
