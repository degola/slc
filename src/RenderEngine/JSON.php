<?php

namespace slc\MVC;

class RenderEngine_JSON extends RenderEngine {
	protected $SignatureHashSalt = null;
	protected $SignatureHashAlgo = 'SHA512';
	protected function initialize() {
		$this->SignatureHashSalt = Base::Factory()->getConfig('RenderEngine_JSON', 'SignatureSalt');
		if(Base::Factory()->getConfig('RenderEngine_JSON', 'SignatureAlgo'))
			$this->SignatureHashAlgo = Base::Factory()->getConfig('RenderEngine_JSON', 'SignatureAlgo');
	}
	public function Fetch() {
		$Data = $this->getTemplateValues();
		$Data = array(
			'ServerTime' => time(),
			'ViewData' => $Data,
			'Signature' => hash($this->SignatureHashAlgo, time().'::'.(!is_null($this->SignatureHashSalt)?$this->SignatureHashSalt.'::':'').json_encode($Data))
		);
		return json_encode($Data);
	}

}

?>