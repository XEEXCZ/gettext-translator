<?php

namespace GettextTranslator;

use Nette;
use Nette\Caching\Cache;
use Nette\Utils\Strings;


class Gettext extends Nette\Object implements Nette\Localization\ITranslator
{
    /** @inject @var FileManager */
    public $fileManager;

    /* @var string */
    public static $namespace = 'GettextTranslator-Gettext';

    /** @var array */
    protected $files = array();

    /** @var string */
    protected $lang;

    /** @var Nette\Http\Session */
    protected $session;

    /** @var array */
    private $dictionary = array();

    /** @var array */
    private $metadata = array();

    /** @var bool */
    private $productionMode;

    /** @var bool */
    private $loaded = false;

    /** @var Nette\Http\SessionSection */
    private $sessionStorage;

    /** @var Nette\Caching\Cache */
    private $cache;

    /** @var Nette\Http\Response */
    private $httpResponse;

    /** @var boolean */
    private $debugMode = false;

    public function __construct(Nette\Http\Session $session, Nette\Caching\IStorage $cacheStorage, Nette\Http\Response $httpResponse)
    {
        $this->session = $session;
        $this->cache = new Nette\Caching\Cache($cacheStorage, self::$namespace);
        $this->httpResponse = $httpResponse;
        $this->fileManager = new FileManager();
        /*
        if (!isset($sessionStorage->newStrings) || !is_array($sessionStorage->newStrings)) {
            $sessionStorage->newStrings = array();
        }
        */
    }


    /**
     * Sets debug mode.
     * @param boolean $debugMode
     */
    public function setDebugMode($debugMode)
    {
        $this->debugMode = (bool)$debugMode;
        if ($debugMode) {
            $this->sessionStorage = $this->session->getSection(self::$namespace);
        }
        return $this;
    }

    /**
     * Add file to parse
     * @param string $dir
     * @param string $identifier
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function addFile($dir, $identifier)
    {
        if (isset($this->files[$identifier])) {
            throw new \InvalidArgumentException("Language file identified '$identifier' is already registered.");
        }

        if (is_dir($dir)) {
            $this->files[$identifier] = $dir;

        } else {
            throw new \InvalidArgumentException("Directory '$dir' doesn't exist.");
        }

        return $this;
    }


    /**
     * Get current language
     * @return string
     * @throws Nette\InvalidStateException
     */
    public function getLang()
    {
        if (empty($this->lang)) {
            throw new Nette\InvalidStateException('Language must be defined.');
        }

        return $this->lang;
    }


    /**
     * Set new language
     * @param $lang
     * @return $this|null
     */
    public function setLang($lang)
    {
        if (empty($lang)) {
            throw new Nette\InvalidStateException('Language must be nonempty string.');
        }

        if ($this->lang === $lang) {
            return null;
        }

        $this->lang = $lang;
        $this->dictionary = array();
        $this->loaded = false;

        return $this;
    }


    /**
     * Set production mode (has influence on cache usage)
     * @param $mode
     * @return $this
     */
    public function setProductionMode($mode)
    {
        $this->productionMode = (bool)$mode;
        return $this;
    }


    /**
     * Translate given string
     * @param string $message
     * @param int $form plural form (positive number)
     * @return string
     */
    public function translate($message, $form = 1)
    {
        $this->loadDictonary();

        $message = (string)$message;
        $message_plural = null;
        $args = func_get_args();

        if (is_array($form) && $form !== null) {
            $message_plural = current($form);
            $form = (int)end($form);

        } else if (is_numeric($form)) {
            $form = (int)$form;
            if ($form < 2) {
                $message_plural = 0;
            } else if ($form < 5) {
                $message_plural = 1;
            } else {
                $message_plural = 2;
            }

        } else if (!is_int($form) || $form === null) {
            $form = 1;
        }


        if (!empty($message) && isset($this->dictionary[$message])) {
            $tmp = preg_replace('/([a-z]+)/', '$$1', "n=$form;" . $this->metadata[$this->dictionary[$message]['file']]['Plural-Forms']);
            eval($tmp);

            $message = $this->dictionary[$message]['translation'];
            if (!empty($message)) {
                $message = (is_array($message) && $message_plural !== null && isset($message[$message_plural])) ? $message[$message_plural] : $message;
            }

        } else {
            if ($this->debugMode && (!$this->httpResponse->isSent() || $this->sessionStorage)) {
                if (!isset($this->sessionStorage->newStrings[$this->lang])) {
                    $this->sessionStorage->newStrings[$this->lang] = array();
                }
                $this->sessionStorage->newStrings[$this->lang][$message] = empty($message_plural) ? array($message) : array($message, $message_plural);
            }

            if ($form > 1 && !empty($message_plural)) {
                //$message = $message_plural;
            }
        }

        if (is_array($message)) {
            $message = current($message);
        }

        if (count($args) > 1) {
            array_shift($args);
            if (is_array(current($args)) || current($args) === null) {
                array_shift($args);
            }

            if (count($args) == 1 && is_array(current($args))) {
                $args = current($args);
            }

            $message = str_replace(array('%label', '%name', '%value'), array('#label', '#name', '#value'), $message);
            if (count($args) > 0 && $args != null) {
                $message = vsprintf($message, $args);
            }
            $message = str_replace(array('#label', '#name', '#value'), array('%label', '%name', '%value'), $message);
        }

        return $message;
    }


    /**
     * Get count of plural forms
     * @return int
     */
    public function getVariantsCount()
    {
        $this->loadDictonary();
        $files = array_keys($this->files);

        if (isset($this->metadata[$files[0]]['Plural-Forms'])) {
            return (int)substr($this->metadata[$files[0]]['Plural-Forms'], 9, 1);
        }

        return 1;
    }


    /**
     * Get translations strings
     * @return array
     */
    public function getStrings($file = null)
    {
        $this->loadDictonary();

        $newStrings = array();
        $result = array();

        if ($this->debugMode && isset($this->sessionStorage->newStrings[$this->lang])) {
            foreach (array_keys($this->sessionStorage->newStrings[$this->lang]) as $original) {
                if (trim($original) != '') {
                    $newStrings[$original] = false;
                }
            }
        }

        foreach ($this->dictionary as $original => $data) {
            if (trim($original) != '') {
                if ($file && $data['file'] === $file) {
                    $result[$original] = $data['translation'];

                } else {
                    $result[$data['file']][$original] = $data['translation'];
                }
            }
        }

        if ($file) {
            return array_merge($newStrings, $result);

        } else {
            foreach ($this->getFiles() as $identifier => $path) {
                if (!isset($result[$identifier])) {
                    $result[$identifier] = array();
                }
            }

            return array('newStrings' => $newStrings) + $result;
        }
    }


    /**
     * Get loaded files
     * @return array
     */
    public function getFiles()
    {
        $this->loadDictonary();
        return $this->files;
    }


    /**
     * Set translation string(s)
     * @param string|array $message original string(s)
     * @param string|array $string translation string(s)
     * @param string
     */
    public function setTranslation($message, $string, $file)
    {
        $this->loadDictonary();

        if ($this->debugMode === true && isset($this->sessionStorage->newStrings[$this->lang]) && array_key_exists($message, $this->sessionStorage->newStrings[$this->lang])) {
            $message = $this->sessionStorage->newStrings[$this->lang][$message];
        }

        $key = is_array($message) ? $message[0] : $message;
        $this->dictionary[$key]['original'] = (array)$message;
        $this->dictionary[$key]['translation'] = (array)$string;
        $this->dictionary[$key]['file'] = $file;
    }


    /**
     * Save dictionary
     * @param string
     */
    public function save($file)
    {
        if (!$this->loaded) {
            throw new Nette\InvalidStateException('Nothing to save, translations are not loaded.');
        }

        if (!isset($this->files[$file])) {
            throw new \InvalidArgumentException("Gettext file identified as '$file' does not exist.");
        }

        $dir = $this->files[$file];
        $path = "$dir/$this->lang.$file";

        $metadata = $this->fileManager->generateMetadata($file, $this->metadata);
        $newStrings = $this->debugMode === true && isset($this->sessionStorage->newStrings[$this->lang])
            ? $this->sessionStorage->newStrings[$this->lang]
            : array();

        $this->fileManager->buildMOFile("$path.mo", $file, $metadata, $this->dictionary);
        $this->fileManager->buildPOFile("$path.po", $file, $metadata, $this->dictionary, $newStrings);

        if ($this->debugMode === true && isset($this->sessionStorage->newStrings[$this->lang])) {
            unset($this->sessionStorage->newStrings[$this->lang]);
        }

        if ($this->productionMode) {
            $this->cache->clean(array(
                Cache::TAGS => 'dictionary-' . $this->lang,
            ));
        }
    }


    /**
     * Load data
     */
    protected function loadDictonary()
    {
        if (!$this->loaded) {
            if (empty($this->files)) {
                throw new Nette\InvalidStateException('Language file(s) must be defined.');
            }

            if ($this->productionMode && isset($this->cache['dictionary-' . $this->lang])) {
                $this->dictionary = $this->cache['dictionary-' . $this->lang];

            } else {
                $files = array();
                foreach ($this->files as $identifier => $dir) {
                    $path = "$dir/$this->lang.$identifier.mo";
                    if (file_exists($path)) {
                        $this->parseFile($path, $identifier);
                        $files[] = $path;
                    }
                }

                if ($this->productionMode) {
                    $this->cache->save('dictionary-' . $this->lang, $this->dictionary, array(
                        Cache::EXPIRE => '+ 1 hour',
                        Cache::FILES => $files,
                        Cache::TAGS => array('dictionary-' . $this->lang),
                    ));
                }
            }

            $this->loaded = true;
        }
    }


    /**
     * Parse dictionary file
     * @param string $file file path
     * @param string
     */
    private function parseFile($file, $identifier)
    {
        $f = @fopen($file, 'rb');
        if (@filesize($file) < 10) {
            throw new \InvalidArgumentException("'$file' is not a gettext file.");
        }

        $endian = false;
        $read = function ($bytes) use ($f, $endian) {
            $data = fread($f, 4 * $bytes);
            return $endian === false ? unpack('V' . $bytes, $data) : unpack('N' . $bytes, $data);
        };

        $input = $read(1);
        if (Strings::lower(substr(dechex($input[1]), -8)) == '950412de') {
            $endian = false;

        } else if (Strings::lower(substr(dechex($input[1]), -8)) == 'de120495') {
            $endian = true;

        } else {
            throw new \InvalidArgumentException("'$file' is not a gettext file.");
        }

        $input = $read(1);

        $input = $read(1);
        $total = $input[1];

        $input = $read(1);
        $originalOffset = $input[1];

        $input = $read(1);
        $translationOffset = $input[1];

        fseek($f, $originalOffset);
        $orignalTmp = $read(2 * $total);
        fseek($f, $translationOffset);
        $translationTmp = $read(2 * $total);

        for ($i = 0; $i < $total; ++$i) {
            if ($orignalTmp[$i * 2 + 1] != 0) {
                fseek($f, $orignalTmp[$i * 2 + 2]);
                $original = @fread($f, $orignalTmp[$i * 2 + 1]);

            } else {
                $original = '';
            }

            if ($translationTmp[$i * 2 + 1] != 0) {
                fseek($f, $translationTmp[$i * 2 + 2]);
                $translation = fread($f, $translationTmp[$i * 2 + 1]);
                if ($original === '') {
                    $this->metadata = $this->fileManager->parseMetadata($translation, $identifier, $this->metadata);
                    continue;
                }

                $original = explode("\0", $original);
                $translation = explode("\0", $translation);

                $key = isset($original[0]) ? $original[0] : $original;
                $this->dictionary[$key]['original'] = $original;
                $this->dictionary[$key]['translation'] = $translation;
                $this->dictionary[$key]['file'] = $identifier;
            }
        }
    }

}
