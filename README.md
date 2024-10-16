# Find and extract translations from a Laravel application

## Introduction

Welcome to **magarrent/laravel-find-missing-translations**, a Laravel package designed to help developers find and manage missing translations within their applications. This tool automates the tedious task of identifying untranslated strings and ensuring that all parts of your application support multilingual features. By streamlining the translation process, this package helps maintain consistency and improve user experience across different languages.

## Features

- **Automatic Detection**: Scans your Laravel application to identify missing translations.
- **Group and String Keys**: Supports both group-based and string-based translation keys.
- **Customizable Patterns**: Configurable to recognize different translation functions.
- **Sorting and Exporting**: Automatically sorts and exports translations for better organization.
- **Exclusion Capabilities**: Ability to exclude specific languages or groups from the search.

## Requirements

- **PHP**: ^8.2
- **Laravel**: Compatible with Laravel versions 10.0 and 11.0

## Installation

To install the package, you can use Composer. Run the following command in your terminal:

```bash
composer require magarrent/laravel-find-missing-translations
```

Once the package is installed, it will automatically register the service provider and facade.

## Usage

To use the package, you can execute the following Artisan command to find and extract missing translations:

```bash
php artisan find:translations
```

This command will scan your application for translation keys and identify any that are missing from your language files.

### Readme generated with [DocuWriter.ai](https://app.docuwriter.ai)
[![DocuWriter.ai Logo](https://app.docuwriter.ai/img/logo-horizontal.png){width=350}](https://app.docuwriter.ai)



## Contributing

Contributions to this project are welcome. If you encounter issues or have suggestions for improvements, feel free to open an issue or submit a pull request on the [GitHub repository](https://github.com/magarrent/laravel-find-missing-translations).

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE.md) file for more information.