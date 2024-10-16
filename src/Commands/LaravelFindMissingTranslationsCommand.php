<?php

namespace Magarrent\LaravelFindMissingTranslations\Commands;

use Illuminate\Console\Command;

class LaravelFindMissingTranslationsCommand extends Command
{
    protected $signature = 'find:translations';

    protected $description = 'Find and extract translations from the application';

    protected $config = [
        'exclude_groups' => [],
        'exclude_langs' => [],
        'sort_keys' => false,
        'trans_functions' => [
            'trans',
            'trans_choice',
            'Lang::get',
            'Lang::choice',
            'Lang::trans',
            'Lang::transChoice',
            '@lang',
            '@choice',
            '__',
            '$trans.get',
        ],
    ];

    protected $translationsPath;

    public function __construct()
    {
        parent::__construct();
        $this->translationsPath = base_path('lang');
    }

    public function handle()
    {
        $this->findAndSaveTranslations();

        return self::SUCCESS;
    }

    public function findAndSaveTranslations($path = null)
    {
        $path = $path ?: base_path();
        $groupKeys = [];
        $stringKeys = [];

        $groupPattern = "[^\w|>]" . '(' . implode('|', $this->config['trans_functions']) . ')' . "\(" . "[\'\"]" .
            '([a-zA-Z0-9_-]+([.][^\1)\ ]+)+)' . "[\'\"]" . "[\),]";
        $stringPattern = "[^\w](" . implode('|', $this->config['trans_functions']) . ")\(\s*(?P<quote>['\"])" .
            "(?P<string>(?:\\\k{quote}|(?!\k{quote}).)*)\k{quote}\s*[\),]";

        $finder = new Finder;
        $finder->in($path)->exclude('storage')->exclude('vendor')->exclude('lang')->name('*.php')->name('*.twig')->name('*.vue')->files();

        foreach ($finder as $file) {
            if (preg_match_all("/$groupPattern/siU", $file->getContents(), $matches)) {
                $groupKeys = array_merge($groupKeys, $matches[2]);
            }

            if (preg_match_all("/$stringPattern/siU", $file->getContents(), $matches)) {
                $stringKeys = array_merge($stringKeys, $matches['string']);
            }
        }

        $groupKeys = array_unique($groupKeys);
        $stringKeys = array_unique($stringKeys);


        $this->saveGroupKeys($groupKeys);
        $this->saveStringKeys($stringKeys);
    }

    protected function saveGroupKeys(array $groupKeys)
    {
        $locales = $this->getAvailableLocales();

        foreach ($groupKeys as $fullKey) {
            [$group, $key] = explode('.', $fullKey, 2);

            foreach ($locales as $locale) {
                if (!in_array($locale, $this->config['exclude_langs'])) {
                    $filePath = $this->getFilePath($locale, $group);
                    $translations = $this->loadTranslations($filePath);

                    if (!array_key_exists($key, $translations)) {
                        $translations = array_merge_recursive($translations, $this->dotNotationToArray($key, $key));

                        if ($this->config['sort_keys']) {
                            ksort($translations);
                        }

                        $this->saveToFile($filePath, $translations);
                    }
                }
            }
        }
    }

    protected function saveStringKeys(array $stringKeys)
    {
        $locales = $this->getAvailableLocales();

        foreach ($locales as $locale) {
            if (!in_array($locale, $this->config['exclude_langs'])) {
                $filePath = $this->getFilePath($locale, '_json');
                $translations = $this->loadTranslations($filePath);

                $changed = false;
                foreach ($stringKeys as $key) {
                    if (strpos($key, '.') === false) {  // Only process keys without dots
                        if (!array_key_exists($key, $translations)) {
                            $translations[$key] = $key;
                            $changed = true;
                        }
                    } else {
                    }
                }

                if ($changed) {
                    if ($this->config['sort_keys']) {
                        ksort($translations);
                    }
                    $this->saveToFile($filePath, $translations);
                } else {
                }
            }
        }
    }

    protected function saveTranslation($group, $key, $isJsonString)
    {
        $locales = $this->getAvailableLocales();

        foreach ($locales as $locale) {
            if (!in_array($locale, $this->config['exclude_langs'])) {
                $filePath = $this->getFilePath($locale, $group);

                // Load existing translations
                $translations = $this->loadTranslations($filePath);

                // Save the new key if it doesn't exist or the value is empty
                if (!array_key_exists($key, $translations) || empty($translations[$key])) {
                    if ($isJsonString) {
                        $translations[$key] = $key;
                    } else {
                        $translations = array_merge_recursive($translations, $this->dotNotationToArray($key, $key));
                    }

                    // Sort keys if needed
                    if ($this->config['sort_keys']) {
                        ksort($translations);
                    }

                    // Save back to the file
                    $this->saveToFile($filePath, $translations);
                }
            }
        }
    }

    protected function dotNotationToArray($key, $value)
    {
        $result = [];
        $parts = explode('.', $key);
        $current = &$result;

        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }

        $current = $value;

        return $result;
    }

    protected function getFilePath($locale, $group)
    {
        if ($group === '_json') {
            return $this->translationsPath . DIRECTORY_SEPARATOR . $locale . '.json';
        }

        return $this->translationsPath . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $group . '.php';
    }

    protected function loadTranslations($filePath)
    {
        if (file_exists($filePath)) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);

            if ($extension === 'json') {
                return json_decode(file_get_contents($filePath), true) ?: [];
            }

            return include $filePath;
        }

        return [];
    }

    protected function saveToFile($filePath, array $translations)
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        if ($extension === 'json') {
            $content = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $content = "<?php\n\nreturn " . var_export($translations, true) . ";\n";
        }

        file_put_contents($filePath, $content);
    }

    protected function getAvailableLocales()
    {
        // Scan the lang directory for available locales
        $directories = array_filter(glob($this->translationsPath . '/*'), 'is_dir');

        return array_map('basename', $directories);
    }

    public function exportAllTranslations()
    {
        $finder = new Finder;
        $finder->in($this->translationsPath)->name('*.php')->name('*.json')->files();

        foreach ($finder as $file) {
            $locale = $file->getRelativePath();
            $group = $file->getBasename('.php');
            $path = $file->getRealPath();

            // Load existing translations
            $translations = $this->loadTranslations($path);

            // Save back to the file (to ensure correct formatting and sorting)
            $this->saveToFile($path, $translations);
        }
    }
}
