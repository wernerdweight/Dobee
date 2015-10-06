<?php

namespace WernerDweight\Dobee\Transformer;

use WernerDweight\Dobee\Transformer\Inflect;

class Transformer {

	public static function smurf($string,$smurf = 'dobee'){
		return $smurf.'_'.$string;
	}

	public static function camelCaseToUnderscore($string){
		preg_match_all('/([A-Z\.][A-Z0-9\.]*(?=$|[A-Z\.][a-z0-9\.])|[A-Za-z\.][a-z0-9\.]+)/',$string,$matches);
		$words = $matches[0];
		foreach ($words as $key => $word) {
			$words[$key] = ($word == strtoupper($word) ? strtolower($word) : lcfirst($word));
		}
		return implode('_',$words);
	}

	public static function underscoreToCamelCase($string){
		return ucfirst(str_replace(' ','',ucwords(str_replace('_',' ',$string))));
	}

	public static function pluralize($string){
		return Inflect::pluralize($string);
	}

	public static function singularize($string){
		return Inflect::singularize($string);
	}

	public static function strip($string){
		/// get position of the separating dot
		$pos = intval(strpos($string,'.'));
		/// if there is a dot, strip string
		if($pos > 0){
			return substr($string,$pos+1);
		}
		else{
			return $string;
		}
	}

}
