Gettext Translator
===

Gettext Translator based on Schmutzka gettexttranslator. 

This tool that enables simple and user friendly translation of your texts via panel in debug bar.
Works only with the Nette 2.3.10

No need to edit or operate with .po/.mo files.

This repository includes php strict standards patches and using convetional non BC tagging!

Installation and usage
---

```yaml
translator:
    class: GettextTranslator\Gettext
    setup:
        - setDebugMode(%debugMode%) # sets debug mode
        - addFile(%appDir%/lang, translations) # adds lang directory with identifier "translations"
        - GettextTranslator\Panel::register # registers translation panel
```


Authors in alphabetic order
---

- Filip Klimeš (filip@filipklimes.cz)
- Josef Kufner (jk@frozen-doe.net)
- Miroslav Paulík (https://github.com/castamir)
- Roman Sklenář (http://romansklenar.cz)
- Miroslav Smetana
- Jan Smitka
- Patrik Votoček (patrik@votocek.cz)
- Tomáš Votruba (tomas.vot@gmail.com)
- Václav Vrbka (gmvasek@php-info.cz)
- Jan Wieclaw


Under *New BSD License*
