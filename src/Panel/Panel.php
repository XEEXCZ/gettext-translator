<?php

namespace GettextTranslator\Panel;

use GettextTranslator\Gettext;
use Nette;
use Tracy\Debugger;
use Tracy\IBarPanel;


class Panel extends Nette\Object implements IBarPanel
{

    /** @var string */
    private $xhrHeader = 'X-Translation-Client';

    /** @var string */
    private $languageKey = 'X-GettextTranslator-Lang';

    /** @var string */
    private $fileKey = 'X-GettextTranslator-File';

    /** @var string */
    private $layout;

    /** @var int */
    private $height;

    /** @var Nette\Application\Application */
    private $application;

    /** @var \GettextTranslator\Gettext */
    private $translator;

    /** @var Nette\Http\SessionSection */
    private $sessionStorage;

    /** @var Nette\Http\Request */
    private $httpRequest;

    /** @var Nette\Http\Session */
    private $session;

    /** @var boolean */
    private $debugMode;


    /**
     * @param \Nette\Application\Application $application
     * @param \GettextTranslator\Gettext $translator
     * @param \Nette\Http\Session $session
     * @param \Nette\Http\Request $httpRequest
     * @param $layout
     * @param $height
     */
    public function __construct(Nette\Application\Application $application, Gettext $translator, Nette\Http\Session $session, Nette\Http\Request $httpRequest, $layout, $height)
    {
        $this->application = $application;
        $this->translator = $translator;
        $this->httpRequest = $httpRequest;
        $this->height = $height;
        $this->layout = $layout;
        $this->session = $session;

        $this->processRequest();
    }


    /**
     * Sets debug mode.
     * @param boolean $debugMode
     */
    public function setDebugMode($debugMode)
    {
        $this->debugMode = $debugMode;
        if ($debugMode === true) {
            $this->sessionStorage = $this->session->getSection(Gettext::$namespace);
        }
    }


    /**
     * Return's panel ID
     * @return string
     */
    public function getId()
    {
        return __CLASS__;
    }


    /**
     * Returns the code for the panel tab
     * @return string
     */
    public function getTab()
    {
        ob_start();
        require __DIR__ . '/tab.latte';
        return ob_get_clean();
    }


    /**
     * Returns the code for the panel itself
     * @return string
     */
    public function getPanel()
    {
        $files = array_keys($this->translator->getFiles());
        $activeFile = $this->getActiveFile($files);

        $strings = $this->translator->getStrings();
        $untranslatedStack = $this->debugMode === true && isset($this->sessionStorage['stack'])
            ? $this->sessionStorage['stack']
            : array();

        foreach ($strings as $string => $data) {
            if (!$data) {
                $untranslatedStack[$string] = false;
            }
        }

        if ($this->debugMode === true) {
            $this->sessionStorage['stack'] = $untranslatedStack;
        }

        foreach ($untranslatedStack as $string => $value) {
            if (!isset($strings[$string])) {
                $strings[$string] = false;
            }
        }

        $translator = $this->translator;

        ob_start();
        require __DIR__ . '/panel.latte';
        return ob_get_clean();
    }


    /**
     * Handles an incomuing request and saves the data if necessary.
     */
    private function processRequest()
    {
        if ($this->httpRequest->isMethod('POST') && $this->httpRequest->isAjax() && $this->httpRequest->getHeader($this->xhrHeader)) {
            $data = json_decode(file_get_contents('php://input'));

            if ($data) {
                if ($this->debugMode === true && $this->sessionStorage) {
                    $stack = isset($this->sessionStorage['stack']) ? $this->sessionStorage['stack'] : array();
                }

                $this->translator->lang = $data->{$this->languageKey};
                $file = $data->{$this->fileKey};
                unset($data->{$this->languageKey}, $data->{$this->fileKey});

                foreach ($data as $string => $value) {
                    $this->translator->setTranslation($string, $value, $file);
                    if ($this->debugMode === true && $this->sessionStorage && isset($stack[$string])) {
                        unset($stack[$string]);
                    }
                }
                $this->translator->save($file);

                if ($this->debugMode === true && $this->sessionStorage) {
                    $this->sessionStorage['stack'] = $stack;
                }
            }

            exit;
        }
    }


    /**
     * Return an ordinal number suffix
     * @param string $count
     * @return string
     */
    protected function ordinalSuffix($count)
    {
        switch (substr($count, -1)) {
            case '1':
                return 'st';
                break;
            case '2':
                return 'nd';
                break;
            case '3':
                return 'rd';
                break;
            default:
                return 'th';
                break;
        }
    }

    /**
     * Register this panel
     * @param \Nette\Application\Application $application
     * @param \GettextTranslator\Gettext $translator
     * @param \Nette\Http\Session $session
     * @param \Nette\Http\Request $httpRequest
     * @param $layout
     * @param $height
     * @return void
     */
    public static function register(Nette\Application\Application $application, Gettext $translator, Nette\Http\Session $session, Nette\Http\Request $httpRequest, $layout = null, $height = null)
    {
        Debugger::getBar()->addPanel(new static($application, $translator, $session, $httpRequest, $layout, $height));
    }


    /**
     * Get active file name
     * @param array
     * @return string
     */
    protected function getActiveFile($files)
    {
        if ($this->application == null) {
            return null;
        }

        $tmp = explode(':', $this->application->presenter->name);

        if (count($tmp) >= 2) {
            $module = strtolower($tmp[0]);
            if (isset($files[$module])) {
                return $module;
            }
        }

        return $files[0];
    }

}
