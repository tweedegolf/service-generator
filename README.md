# Tweede Golf Symfony Service Generator

Generates PHP class files from service definitions found in app/config/services.yml

Usage: `bin/symfony generate:service`

This command will prompt you to generate all
classes that are defines in `services.yml` but do not exist in the current namespace.

## Installation
Using [Composer][composer] add the bundle to your requirements:

```bash
composer require --dev tweedegolf/service-generator
```

### Add the bundle to your AppKernel
Finally add the bundle in `app/AppKernel.php`:

```php
public function registerBundles()
{
    $bundles = [
        // ...
    ];

    if (in_array($this->getEnvironment(), ['dev', 'test'])) {
        // ...
        $bundles[] = new TweedeGolf\ServiceGenerator\ServiceGeneratorBundle();
    }

    return $bundles;
}
```

[composer]: https://getcomposer.org/
