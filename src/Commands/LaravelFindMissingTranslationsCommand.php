<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class FindTranslationsCommand extends Command
{
    protected $signature = 'find:translations {--path= : Path to scan for translations} {--detailed : Show more detailed output}';

    protected $description = 'Find and extract translations from the application';

    protected $config = [
        'exclude_groups' => [],
        'exclude_langs' => [],
        'sort_keys' => false,
        'trans_functions' => [
            'trans', 'trans_choice', 'Lang::get', 'Lang::choice', 'Lang::trans',
            'Lang::transChoice', '@lang', '@choice', '__', '$trans.get',
        ],
    ];

    protected $translationsPath;

    protected $verbose = false;

    public function __construct()
    {
        parent::__construct();
        $this->translationsPath = base_path('lang');
    }

    public function handle()
    {
        $this->verbose = $this->option('detailed') || $this->option('verbose');
        $path = $this->option('path') ?: base_path();

        $this->info('Scanning for translations in: '.$path);
        $this->findAndSaveTranslations($path);
        $this->info('Translation scan completed.');
    }

    public function findAndSaveTranslations($path = null)
    {
        $path = $path ?: base_path();
        $groupKeys = [];
        $stringKeys = [];

        // Multiple patterns to match different translation function calls
        $patterns = [
            // @lang directive pattern
            "/@lang\(\s*['\"]([^'\"]+)['\"]\s*\)/",

            // __ helper function pattern
            "/__\(\s*['\"]([^'\"]+)['\"]\s*\)/",

            // __ helper function pattern with parameters
            "/__\(\s*['\"]([^'\"]+)['\"]\s*,/",

            // trans helper function pattern
            "/trans\(\s*['\"]([^'\"]+)['\"]\s*\)/",

            // trans helper function pattern with parameters
            "/trans\(\s*['\"]([^'\"]+)['\"]\s*,/",

            // Lang::get pattern
            "/Lang::get\(\s*['\"]([^'\"]+)['\"]\s*\)/",

            // Lang::get pattern with parameters
            "/Lang::get\(\s*['\"]([^'\"]+)['\"]\s*,/",

            // trans_choice helper function pattern
            "/trans_choice\(\s*['\"]([^'\"]+)['\"]\s*,/",

            // Lang::choice pattern
            "/Lang::choice\(\s*['\"]([^'\"]+)['\"]\s*,/",

            // Blade component attribute translations - single quotes inside double quotes
            "/:[a-zA-Z0-9_-]+=\"__\(['\"]([^'\"]+)['\"]\)/",

            // Blade component attribute translations - single quotes inside single quotes
            "/:[a-zA-Z0-9_-]+='__\(['\"]([^'\"]+)['\"]\)/",

            // Blade component attribute translations - double quotes inside double quotes with escaped quotes
            '/:[a-zA-Z0-9_-]+="__\(\"([^\"]+)\"\)/',

            // Blade component regular attribute with translation
            '/[a-zA-Z0-9_-]+="{{[\s]*__\([\'"]([^\'"]+)[\'"][\s]*\)[\s]*}}"/i',

            // Blade component regular attribute with translation (single quotes)
            "/[a-zA-Z0-9_-]+='{{[\s]*__\(['\"]([^'\"]+)['\"][\s]*\)[\s]*}}'/i",

            // {{ __('text') }} pattern
            "/\{{\s*__\(['\"]([^'\"]+)['\"]\)\s*}}/",

            // {!! __('text') !!} pattern
            "/\{!!\s*__\(['\"]([^'\"]+)['\"]\)\s*!!}/",
        ];

        // Component special patterns that need different handling
        $specialPatterns = [
            // Special handling for x-component with :attribute="__('...')" pattern
            '/<x-[^>]*?(\s+:[a-zA-Z0-9_-]+\s*=\s*"__\([\'"]([^\'"]+)[\'"][^>]*?)>/' => 2,
        ];

        $finder = new Finder;
        $finder->in($path)
            ->exclude('storage')
            ->exclude('vendor')
            ->exclude('lang')
            ->name(['*.php', '*.twig', '*.vue', '*.blade.php'])
            ->files();

        if ($this->verbose) {
            $this->info('Scanning files for translations...');
            $this->output->progressStart($finder->count());
        }

        foreach ($finder as $file) {
            $content = $file->getContents();
            $filePath = $file->getRelativePathname();
            $fileTranslations = [];

            // Process standard patterns
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[1] as $match) {
                        // Check if it starts with a group prefix (e.g., "members.")
                        if (preg_match('/^[a-zA-Z0-9_-]+\./', $match)) {
                            $groupKeys[] = $match;
                            $fileTranslations[] = $match;
                        } else {
                            // No group prefix, treat as JSON string
                            $stringKeys[] = $match;
                            $fileTranslations[] = $match;
                        }
                    }
                }
            }

            // Process special patterns that need custom handling
            foreach ($specialPatterns as $pattern => $matchIndex) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[$matchIndex] as $match) {
                        // Check if it starts with a group prefix
                        if (preg_match('/^[a-zA-Z0-9_-]+\./', $match)) {
                            $groupKeys[] = $match;
                            $fileTranslations[] = $match;
                        } else {
                            $stringKeys[] = $match;
                            $fileTranslations[] = $match;
                        }
                    }
                }
            }

            if ($this->verbose) {
                $this->output->progressAdvance();

                if (! empty($fileTranslations)) {
                    $fileTranslations = array_unique($fileTranslations);
                    $this->line("\n<info>Found translations in {$filePath}:</info>");
                    foreach ($fileTranslations as $translation) {
                        $this->line("  - {$translation}");
                    }
                }
            }
        }

        if ($this->verbose) {
            $this->output->progressFinish();
        }

        $groupKeys = array_unique($groupKeys);
        $stringKeys = array_unique($stringKeys);

        $this->info('Found '.count($groupKeys).' group keys and '.count($stringKeys).' string keys.');

        $this->saveGroupKeys($groupKeys);
        $this->saveStringKeys($stringKeys);
    }

    protected function saveGroupKeys(array $groupKeys)
    {
        $locales = $this->getAvailableLocales();
        $newTranslations = 0;

        foreach ($groupKeys as $fullKey) {
            // Get only the first dot as separator between group and key
            $parts = explode('.', $fullKey, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$group, $key] = $parts;

            if (empty($group)) {
                continue;
            }

            foreach ($locales as $locale) {
                if (! in_array($locale, $this->config['exclude_langs'])) {
                    $filePath = $this->getFilePath($locale, $group);

                    // Ensure directory exists
                    $directory = dirname($filePath);
                    if (! is_dir($directory)) {
                        mkdir($directory, 0755, true);
                    }

                    $translations = $this->loadTranslations($filePath);

                    if (! array_key_exists($key, $translations)) {
                        $translations[$key] = $key;
                        $newTranslations++;

                        if ($this->verbose) {
                            $this->line("<comment>Added missing translation</comment>: {$locale}.{$group}.{$key}");
                        }

                        if ($this->config['sort_keys']) {
                            ksort($translations);
                        }

                        $this->saveToFile($filePath, $translations);
                    }
                }
            }
        }

        if ($newTranslations > 0) {
            $this->info("Added {$newTranslations} new translations to group files");
        }
    }

    protected function saveStringKeys(array $stringKeys)
    {
        $locales = $this->getAvailableLocales();
        $newTranslations = 0;

        foreach ($locales as $locale) {
            if (! in_array($locale, $this->config['exclude_langs'])) {
                $filePath = $this->getFilePath($locale, '_json');

                // Ensure parent directory exists
                $directory = dirname($filePath);
                if (! is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                $translations = $this->loadTranslations($filePath);

                $changed = false;
                foreach ($stringKeys as $key) {
                    if (strpos($key, '.') === false) {  // Only process keys without dots
                        if (! array_key_exists($key, $translations)) {
                            $translations[$key] = $key;
                            $changed = true;
                            $newTranslations++;

                            if ($this->verbose) {
                                $this->line("<comment>Added missing string translation</comment>: {$locale}.{$key}");
                            }
                        }
                    }
                }

                if ($changed) {
                    if ($this->config['sort_keys']) {
                        ksort($translations);
                    }
                    $this->saveToFile($filePath, $translations);
                }
            }
        }

        if ($newTranslations > 0) {
            $this->info("Added {$newTranslations} new translations to JSON files");
        }
    }

    protected function saveTranslation($group, $key, $isJsonString)
    {
        $locales = $this->getAvailableLocales();

        foreach ($locales as $locale) {
            if (! in_array($locale, $this->config['exclude_langs'])) {
                $filePath = $this->getFilePath($locale, $group);

                // Load existing translations
                $translations = $this->loadTranslations($filePath);

                // Save the new key if it doesn't exist or the value is empty
                if (! array_key_exists($key, $translations) || empty($translations[$key])) {
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
            if (! isset($current[$part])) {
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
            return $this->translationsPath.DIRECTORY_SEPARATOR.$locale.'.json';
        }

        return $this->translationsPath.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$group.'.php';
    }

    protected function loadTranslations($filePath)
    {
        if (file_exists($filePath)) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);

            if ($extension === 'json') {
                return json_decode(file_get_contents($filePath), true) ?: [];
            }

            $content = include $filePath;

            return is_array($content) ? $content : [];
        }

        return [];
    }

    protected function saveToFile($filePath, array $translations)
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if ($extension === 'json') {
            $content = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $content = "<?php\n\nreturn ".var_export($translations, true).";\n";
        }

        file_put_contents($filePath, $content);

        if ($this->verbose) {
            $this->line('<info>Updated translations file</info>: '.basename($filePath));
        }
    }

    protected function getAvailableLocales()
    {
        // Scan the lang directory for available locales
        $directories = array_filter(glob($this->translationsPath.'/*'), 'is_dir');

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
