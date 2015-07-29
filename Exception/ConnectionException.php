<?php

namespace WernerDweight\Dobee\Exception;

class ConnectionFoundException extends \RuntimeException{

	public function __construct($message = null,$code = null,\Exception $previous = null){
		if(is_null($message)){
			$message = 'There was an error connecting to the database!';
		}
		parent::__construct($message,$code,$previous);
	}

}
