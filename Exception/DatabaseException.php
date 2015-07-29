<?php

namespace WernerDweight\Dobee\Exception;

class DatabaseException extends \RuntimeException{

	public function __construct($message = null,$code = null,\Exception $previous = null){
		if(is_null($message)){
			$message = 'A database error occured!';
		}
		parent::__construct($message,$code,$previous);
	}

}
