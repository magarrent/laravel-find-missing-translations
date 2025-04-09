<?php

namespace Magarrent\LaravelFindMissingTranslations\Commands;

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

        // Always try to clean up vendor.json after scanning
        $vendorJsonPath = $this->translationsPath.DIRECTORY_SEPARATOR.'vendor.json';
        if (file_exists($vendorJsonPath)) {
            $this->cleanupVendorJson();
        }

        $this->info('Translation scan completed.');
    }

    public function findAndSaveTranslations($path = null)
    {
        $path = $path ?: base_path();
        $groupKeys = [];
        $stringKeys = [];

        // Multiple patterns to match different translation function calls
        $patterns = [
            // @lang directive pattern (no parameters)
            "/@lang\(\s*['\"]([^'\"]+)['\"]\s*\)/",

            // @lang directive pattern with parameters
            "/@lang\(\s*['\"]([^'\"]+)['\"][\s]*,[\s]*\[/",

            // __ helper function pattern (no parameters)
            "/__\(\s*['\"]([^'\"]+)['\"]\s*\)/",

            // __ helper function pattern with parameters
            "/__\(\s*['\"]([^'\"]+)['\"][\s]*,[\s]*\[/",

            // __ helper function pattern with parameters (alternate format)
            "/__\(\s*['\"]([^'\"]+)['\"][\s]*,[\s]*array\(/",

            // trans helper function pattern (no parameters)
            "/trans\(\s*['\"]([^'\"]+)['\"]\s*\)/",

            // trans helper function pattern with parameters
            "/trans\(\s*['\"]([^'\"]+)['\"][\s]*,[\s]*\[/",

            // trans helper function pattern with parameters (alternate format)
            "/trans\(\s*['\"]([^'\"]+)['\"][\s]*,[\s]*array\(/",

            // Lang::get pattern (no parameters)
            "/Lang::get\(\s*['\"]([^'\"]+)['\"]\s*\)/",

            // Lang::get pattern with parameters
            "/Lang::get\(\s*['\"]([^'\"]+)['\"][\s]*,[\s]*\[/",

            // Lang::get pattern with parameters (alternate format)
            "/Lang::get\(\s*['\"]([^'\"]+)['\"][\s]*,[\s]*array\(/",

            // trans_choice helper function pattern
            "/trans_choice\(\s*['\"]([^'\"]+)['\"]\s*,/",

            // Lang::choice pattern
            "/Lang::choice\(\s*['\"]([^'\"]+)['\"]\s*,/",

            // Blade component attribute translations - single quotes inside double quotes
            "/:[a-zA-Z0-9_-]+=\"__\(['\"]([^'\"]+)['\"]\)/",

            // Blade component attribute translations with parameters - single quotes inside double quotes
            "/:[a-zA-Z0-9_-]+=\"__\(['\"]([^'\"]+)['\"][\s]*,[\s]*\[/",

            // Blade component attribute translations - single quotes inside single quotes
            "/:[a-zA-Z0-9_-]+='__\(['\"]([^'\"]+)['\"]\)/",

            // Blade component attribute translations with parameters - single quotes inside single quotes
            "/:[a-zA-Z0-9_-]+='__\(['\"]([^'\"]+)['\"][\s]*,[\s]*\[/",

            // Blade component attribute translations - double quotes inside double quotes with escaped quotes
            '/:[a-zA-Z0-9_-]+="__\(\"([^\"]+)\"\)/',

            // Blade component regular attribute with translation
            '/[a-zA-Z0-9_-]+="{{[\s]*__\([\'"]([^\'"]+)[\'"][\s]*\)[\s]*}}"/i',

            // Blade component regular attribute with translation (single quotes)
            "/[a-zA-Z0-9_-]+='{{[\s]*__\(['\"]([^'\"]+)['\"][\s]*\)[\s]*}}'/i",

            // {{ __('text') }} pattern
            "/\{{\s*__\(['\"]([^'\"]+)['\"]\)\s*}}/",

            // {{ __('text', [...]) }} pattern with parameters
            "/\{{\s*__\(['\"]([^'\"]+)['\"][\s]*,[\s]*\[/",

            // {!! __('text') !!} pattern
            "/\{!!\s*__\(['\"]([^'\"]+)['\"]\)\s*!!}/",

            // {!! __('text', [...]) !!} pattern with parameters
            "/\{!!\s*__\(['\"]([^'\"]+)['\"][\s]*,[\s]*\[/",
        ];

        // Component special patterns that need different handling
        $specialPatterns = [
            // Special handling for x-component with :attribute="__('...')" pattern
            '/<x-[^>]*?(\s+:[a-zA-Z0-9_-]+\s*=\s*"__\([\'"]([^\'"]+)[\'"][^>]*?)>/' => 2,
            // Special handling for x-component with :attribute="@lang('...')" pattern
            '/<x-[^>]*?(\s+:[a-zA-Z0-9_-]+\s*=\s*"@lang\([\'"]([^\'"]+)[\'"][^>]*?)>/' => 2,
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
            if (! in_array($locale, $this->config['exclude_langs']) && $locale !== 'vendor') {
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
        // Never use 'vendor' as the locale for JSON files
        if ($locale === 'vendor' && $group === '_json') {
            $locale = 'en'; // Default to English instead
        }

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
        $locales = [];

        // Scan the lang directory for available locale directories
        $directories = array_filter(glob($this->translationsPath.'/*'), 'is_dir');
        $locales = array_merge($locales, array_map('basename', $directories));

        // Also scan for JSON files (which are locale files themselves)
        $jsonFiles = glob($this->translationsPath.'/*.json');
        foreach ($jsonFiles as $file) {
            $locale = pathinfo($file, PATHINFO_FILENAME);
            // Skip vendor.json or other special files that aren't locales
            if ($locale !== 'vendor' && ! in_array($locale, $locales)) {
                $locales[] = $locale;
            }
        }

        // If no locales found, default to 'en'
        if (empty($locales)) {
            $locales[] = 'en';

            // Create the default en.json file if it doesn't exist
            $enJsonPath = $this->translationsPath.DIRECTORY_SEPARATOR.'en.json';
            if (! file_exists($enJsonPath)) {
                $this->saveToFile($enJsonPath, []);
                $this->info('Created default en.json file');
            }
        }

        return $locales;
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

    protected function cleanupVendorJson()
    {
        $vendorJsonPath = $this->translationsPath.DIRECTORY_SEPARATOR.'vendor.json';
        $this->info('Checking for vendor.json at: '.$vendorJsonPath);

        if (file_exists($vendorJsonPath)) {
            $this->info('Found vendor.json file, attempting to process it...');

            // Try to move translations from vendor.json to en.json before deleting
            $enJsonPath = $this->translationsPath.DIRECTORY_SEPARATOR.'en.json';

            $vendorTranslations = json_decode(file_get_contents($vendorJsonPath), true) ?: [];

            if (! empty($vendorTranslations)) {
                $this->info('Found '.count($vendorTranslations).' translations in vendor.json');

                // Load existing en translations
                $enTranslations = [];
                if (file_exists($enJsonPath)) {
                    $enTranslations = json_decode(file_get_contents($enJsonPath), true) ?: [];
                    $this->info('Found '.count($enTranslations).' existing translations in en.json');
                }

                // Merge translations (vendor translations take priority)
                $mergedTranslations = array_merge($enTranslations, $vendorTranslations);

                // Save to en.json
                if (! empty($mergedTranslations)) {
                    $this->saveToFile($enJsonPath, $mergedTranslations);
                    $this->info('Moved '.count($vendorTranslations).' translations from vendor.json to en.json');
                }
            } else {
                $this->info('vendor.json file is empty or has invalid JSON');
            }

            // Now delete the vendor.json file
            try {
                if (unlink($vendorJsonPath)) {
                    $this->info('Successfully removed vendor.json file');
                } else {
                    $this->error('Failed to remove vendor.json file');
                }
            } catch (\Exception $e) {
                $this->error('Error removing vendor.json: '.$e->getMessage());
            }
        } else {
            $this->info('vendor.json file not found');
        }
    }
}
