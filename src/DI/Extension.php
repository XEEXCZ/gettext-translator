<?php

namespace GettextTranslator\DI;

use Nette;


if (!class_exists('Nette\DI\CompilerExtension')) {
    class_alias('Nette\Config\CompilerExtension', 'Nette\DI\CompilerExtension');
}


class Extension extends Nette\DI\CompilerExtension
{
    /** @var array */
    private $defaults = array(
        'lang' => 'en',
        'files' => array(),
        'layout' => 'horizontal',
        'height' => 465,
        'debugger' => '%debugMode%',
        'visible' => '%debugMode%',
    );


    public function loadConfiguration()
    {
        $config = $this->getConfig($this->defaults);
        $config = Nette\DI\Helpers::expand($config, $this->validateConfig($this->config));

        if (count($config['files']) === 0) {
            throw new InvalidConfigException('At least one language file must be defined.');
        }

        $builder = $this->getContainerBuilder();

        $translator = $builder->addDefinition($this->prefix('translator'));
        $translator->setClass('GettextTranslator\Gettext', array('@session', '@cacheStorage', '@httpResponse'));
        $translator->addSetup('setLang', array($config['lang']));
        $translator->addSetup('setProductionMode', array($config['debugger'] !== true));
        $fileManager = $builder->addDefinition($this->prefix('fileManager'));
        $fileManager->setClass('GettextTranslator\FileManager');

        foreach ($config['files'] as $id => $file) {
            $translator->addSetup('addFile', array($file, $id));
        }

        if ($config['visible']) {
            $translator->addSetup('GettextTranslator\Panel\Panel::register', array('@application', '@self', '@session', '@httpRequest', $config['layout'], $config['height']));
        }
    }

}


class InvalidConfigException extends Nette\InvalidStateException
{

}
