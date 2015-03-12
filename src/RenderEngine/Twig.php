<?php

namespace slc\MVC;

use slc\twig_relative_directory\RelativePath;

class RenderEngine_Twig extends RenderEngine {
	protected $Twig = null;
	protected function initialize() {
		\Twig_Autoloader::register();
		$this->Twig = new \Twig_Environment(
			new \Twig_Loader_Filesystem(Base::Factory()->getConfig('Paths', 'View')),
			array_merge(
				array(
					'cache' => Base::Factory()->getConfig('Paths', 'Cache') + DIRECTORY_SEPARATOR + 'RenderEngine_Twig/',
					'auto_reload' => true,
					'strict_variables' => true,
					'charset' => 'utf-8',
					'debug' => (DEPLOYMENT_STATE!='stable'?true:false)
				),
				(array)Base::Factory()->getConfig('RenderEngine_Twig', null)
			)
		);
		if(function_exists('gettext')) {
			$this->Twig->addExtension(new \Twig_Extensions_Extension_I18n());
			$this->Twig->getExtension('core')->setNumberFormat(0, gettext('DECIMAL_SEPERATOR'), gettext('THOUSANDS_SEPERATOR'));
		}
        if(class_exists('slc\twig_relative_directory\RelativePath')) {
            $this->Twig->addExtension(new RelativePath());
        }
	}
	public function Fetch() {
		try {
			return $this->Twig->render(
				$this->getViewPath(),
				$this->getTemplateValues()
			);
		} catch(\Twig_Error_Loader $ex) {
			// wrap twig error message to make it handlebar for common mistakes / errors
			throw new RenderEngine_Exception(RenderEngine_Exception::INVALID_VIEW, array(
				'View' => $this->getViewPath(),
				'OriginalMessage' => $ex->getMessage()
			), $ex);
		}
	}

}

?>