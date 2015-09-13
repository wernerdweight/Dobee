<?php

namespace WernerDweight\Dobee;

use Symfony\Component\Yaml\Yaml;

use WernerDweight\Dobee\Exception\ConnectionException;
use WernerDweight\Dobee\Exception\InvalidConfigurationException;
use WernerDweight\Dobee\Exception\InvalidModelConfigurationException;
use WernerDweight\Dobee\Generator\Generator;
use WernerDweight\Dobee\Provider\Provider;

class Dobee {

	protected $options;
	protected $connection;
	protected $generator;
	protected $provider;
	protected $entityPath;

	public function __construct($configurationFilePath,$entityPath,$entityNamespace){
		/// set path to store entity scripts and their namespace
		$this->entityPath = $entityPath;
		$this->entityNamespace = $entityNamespace;
		/// check that path exists and is a directory
		if(!is_dir($this->entityPath)){
			throw new InvalidConfigurationException('The path to store entity scripts is not a directory!');
		}
		/// set options from configuration
		$this->setOptions($this->loadConfiguration($configurationFilePath));
		/// connect to database
		$this->connect();
		/// initialize
		$this->initializeGenerator();
		$this->initializeProvider();
	}

	public function __destruct(){
		$this->connection->close();
	}

	protected function loadConfiguration($configurationFilePath){
		if(!is_file($configurationFilePath)){
			/// try to use given string as configuration file contents
			$configurationFileContents = $configurationFilePath;
		}
		else{
			/// load contents of the configuration file
			$configurationFileContents = file_get_contents($configurationFilePath);
		}

		try {
			/// parse configuration
			$options = Yaml::parse($configurationFileContents);
		} catch (Exception $e) {
			throw new InvalidConfigurationException($e);
		}

		return $options;
	}

	protected function setOptions($options){
		if($this->validateOptions($options) === false){
			throw new InvalidConfigurationException();
		}
		else{
			$this->options = $options;
		}
	}

	protected function validateOptions($options){
		if(
			isset($options['db']) &&								/// db must be configured
			isset($options['db']['host']) &&						/// database host must be set
			isset($options['db']['database']) &&					/// database name must be set
			isset($options['db']['user']) &&						/// database user must be set
			array_key_exists('password',$options['db']) &&			/// database password must be set (but can be null)
			array_key_exists('port',$options['db']) &&				/// database port must be set (but can be null)
			isset($options['model']) &&								/// model must be set
			$this->validateModel($options['model']) === true		/// model must be valid
		){
			return true;
		}
		else return false;
	}

	protected function validateModel($model){
		foreach ($model as $entity => $options) {
			if(isset($options['extends']) && !isset($model[$options['extends']])){
				throw new InvalidModelConfigurationException('Entity "'.$entity.'" extends non-existing entity "'.$options['extends'].'"');
			}
			else if(!isset($options['extends']) && !isset($options['primary'])){
				throw new InvalidModelConfigurationException('Entity "'.$entity.'" is missing the primary key!');
			}
			else if(isset($options['primary']) && (!isset($options['properties']) || !array_key_exists($options['primary'],$options['properties']))){
				throw new InvalidModelConfigurationException('Primary key "'.$options['primary'].'" for entity "'.$entity.'" does not exist!');
			}
			if(isset($options['properties']) && is_array($options['properties'])){
				foreach ($options['properties'] as $property => $settings) {
					if(!isset($settings['type'])){
						throw new InvalidModelConfigurationException('Type must be defined for property "'.$property.'" of entity "'.$entity.'"!');
					}
					else if(!in_array($settings['type'],self::getAvailablePropertyTypes())){
						throw new InvalidModelConfigurationException('Property "'.$property.'" of entity "'.$entity.'" can not have type "'.$settings['type'].'" as this type does not exist!');
					}
					else if($settings['type'] == 'string'){
						if(!isset($settings['length'])){
							throw new InvalidModelConfigurationException('Length must be defined for property "'.$property.'" of type "string" of entity "'.$entity.'"!');
						}
						else if(!is_int($settings['length'])){
							throw new InvalidModelConfigurationException('The "length" setting only accepts integer value for property "'.$property.'" of entity "'.$entity.'"!');
						}
					}
					else if(isset($settings['notNull']) && !is_bool($settings['notNull'])){
						throw new InvalidModelConfigurationException('The "notNull" setting only accepts boolean value for property "'.$property.'" of entity "'.$entity.'"!');
					}
				}
			}
			if(isset($options['relations']) && is_array($options['relations'])){
				foreach ($options['relations'] as $relatedEntity => $cardinality) {
					if(!isset($model[$relatedEntity])){
						throw new InvalidModelConfigurationException('Entity "'.$entity.'" can not have a relation with non-existing entity "'.$relatedEntity.'"!');
					}
					else if(!in_array($cardinality,self::getAvailableCardinalityValues())){
						throw new InvalidModelConfigurationException('Entity "'.$entity.'" can not have a relation with cardinality "'.$cardinality.'" as this cardinality does not exist!');
					}
				}
			}
		}
		return true;
	}

	protected static function getAvailablePropertyTypes(){
		return array(
			'bool',
			'int',
			'float',
			'string',
			'text',
			'datetime',
		);
	}

	protected static function getAvailableCardinalityValues(){
		return array(
			'ONE_TO_ONE',
			'<<ONE_TO_ONE',
			'ONE_TO_MANY',
			'MANY_TO_ONE',
			'MANY_TO_MANY',
			'<<MANY_TO_MANY',
		);
	}

	protected function connect(){
		$this->connection = new \mysqli(
			$this->options['db']['host'],
			$this->options['db']['user'],
			($this->options['db']['password'] ? $this->options['db']['password'] : ini_get('mysqli.default_pw')),
			$this->options['db']['database'],
			($this->options['db']['port'] ? $this->options['db']['port'] : ini_get('mysqli.default_port'))
		);
		/// check connection
		if($this->connection->connect_error){
			throw new ConnectionException('Connection failed: '.$this->connection->connect_error);
		} 
	}

	protected function initializeGenerator(){
		$this->generator = new Generator($this->connection,$this->entityPath,$this->entityNamespace,$this->options['model'],$this->options['db']['database']);
	}

	protected function initializeProvider(){
		$this->provider = new Provider($this->connection,$this->entityNamespace,$this->options['model']);
	}

	public function getProvider(){
		return $this->provider;
	}

	public function generate($options){
		return $this->generator->generate($options);
	}

}
