<?php

namespace slc\MVC;

class RenderEngine_Shell extends RenderEngine {
	protected $SignatureHashSalt = null;
	protected $SignatureHashAlgo = 'SHA512';
	protected function initialize() {
	}
	public function Fetch() {
		$Data = $this->getTemplateValues();
		return 'Shell controller assignments:'."\n".print_r($Data, true);
	}

}

?>