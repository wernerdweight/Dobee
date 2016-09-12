<?php

namespace WernerDweight\Dobee\Provider;

class Version {

	public $id;
	public $actionType;
	public $blame;
	public $entityClass;
	public $entityId;
	public $version;
	public $loggedAt;
	public $data;

	public function __construct($data){
		$this->id = $data['id'];
		$this->actionType = $data['action_type'];
		$this->blame = $data['blame'];
		$this->entityClass = $data['entity_class'];
		$this->entityId = $data['entity_id'];
		$this->version = $data['version'];
		$this->loggedAt = new \DateTime($data['logged_at']);
		$this->data = $data['data'];
	}

}
