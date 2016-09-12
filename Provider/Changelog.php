<?php

namespace WernerDweight\Dobee\Provider;

use WernerDweight\Dobee\Provider\Provider;
use WernerDweight\Dobee\Provider\Version;

class Changelog {

	protected $provider;
	protected $entityName;
	protected $entityId;
	protected $versions;

	public function __construct(Provider $provider,$entityName,$entityId){
		$this->provider = $provider;
		$this->entityName = $entityName;
		$this->entityId = $entityId;
		$this->versions = null;
	}

	protected function prepareBlameable($result){
		if(intval($result['blame']) > 0){
			$result['blame'] = $this->provider->fetchBlame($this->entityName,$result['blame']);
		}
		return $result;
	}

	public function getVersion($id){

		if(null !== $this->versions && true === isset($this->versions[$id])){
			return $this->versions[$id];
		}
		else{
			$query = "SELECT * FROM log_storage WHERE entity_class = ? AND entity_id = ? AND id = ? LIMIT 0,1";
			$types = ['s','s','i'];
			$params = [
				$this->entityName,	/// entity_class
				$this->entityId,	/// entity_id
				$id,				/// id
			];
			$result = $this->provider->execute($query,$types,$params);

			if(is_array($result) && count($result)){
				return new Version($this->prepareBlameable($result[0]));
			}

			return null;
		}
	}

	public function getVersions(){

		if(null === $this->versions || false === is_array($this->versions)){
			$query = "SELECT * FROM log_storage WHERE entity_class = ? AND entity_id = ? ORDER BY id DESC";
			$types = ['s','s'];
			$params = [
				$this->entityName,	/// entity_class
				$this->entityId,	/// entity_id
			];
			$result = $this->provider->execute($query,$types,$params);

			$this->versions = [];
			if(is_array($result) && count($result)){
				foreach ($result as $key => $rowData) {
					$this->versions[$rowData['id']] = new Version($this->prepareBlameable($rowData));
				}
			}
		}

		return $this->versions;
	}

}
