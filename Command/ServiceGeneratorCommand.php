<?php

namespace TweedeGolf\ServiceGenerator\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ServiceGeneratorCommand
 * @package TweedeGolf\ServiceGenerator\Command
 */
class ServiceGeneratorCommand extends ContainerAwareCommand
{
    /**
     * @var Definition[]
     */
    private $definitions;

    /**
     * Configure the symfony command.
     */
    protected function configure()
    {
        $this
            ->setName('generate:service')
            ->setDescription('Generate defined and unexisting service classes')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');

        // load service container definitions
        $container = new ContainerBuilder();

        if (!is_file($cachedFile = $this->getContainer()->getParameter('debug.container.dump'))) {
            throw new \LogicException('Debug information about the container could not be found. Please clear the cache and try again.');
        }

        $loader = new XmlFileLoader($container, new FileLocator());
        $loader->load($cachedFile);

        $this->definitions = $container->getDefinitions();

        // load original service yml definitions
        $root_dir = $this->getContainer()->getParameter('kernel.root_dir');
        $services_file = "$root_dir/config/services.yml";

        if (!file_exists($services_file)) {
            throw new \LogicException('The file app/config/services.yml could not be found, make sure this file exists.');
        }

        $services_content = Yaml::parse(file_get_contents($services_file));

        foreach ($services_content['services'] as $key => $definition) {
            if (!class_exists($definition['class'])) {
                $definition['key'] = $key;

                $question = new ConfirmationQuestion(
                    "Generate a service class for <info>{$definition['class']}</info> <comment>[Y/n]</comment> "
                );

                if ($helper->ask($input, $output, $question)) {
                    $this->generateService($output, $definition);
                }
            }
        }
    }

    /**
     * Generate a PHP class file with the constructor arguments as private attributes
     *
     * @param OutputInterface $output
     * @param array $definition
     */
    private function generateService(OutputInterface $output, array $definition)
    {
        $arguments = [];
        $imports = [];

        // get service container definitions to type hint parameters
        $service_definition = $this->definitions[$definition['key']];
        $service_arguments = $service_definition->getArguments();
        $converter = new CamelCaseToSnakeCaseNameConverter();

        foreach ($definition['arguments'] as $key => $argument) {
            if ($argument[0] === '@') {
                $name = substr($argument, 1);
                $namespace = $this->getNamespaceOrInterface($name);

                if (strpos($namespace, '\\') === false) {
                    // Symfony standard dictates that root namespaces should not be separately imported
                    $namespace = '\\' . $namespace;
                } else {
                    $imports[] = $namespace;
                    $namespace = self::getClassName($namespace);
                }

                // convert twice to correctly handle camel+snake case
                $name = $converter->denormalize($name);

                $arguments[] = [
                    'variable' => $converter->denormalize($name),
                    'type' => $namespace,
                    'hint' => $namespace,
                ];

            } else if ($argument[0] === '%') {
                $name = substr($argument, 1, -1);
                $type = gettype($service_arguments[$key]);

                $arguments[] = [
                    'variable' => $converter->denormalize($name),
                    'type' => $type,
                    'hint' => $type === 'array' ? $type : ''
                ];
            }
        }

        sort($imports);

        $source = $this->getContainer()->get('twig')->render(realpath(__DIR__ . '/../Resources/service_template.php.twig'), [
            'class_name' => self::getClassName($definition['class']),
            'package' => self::getPackageName($definition['class']),
            'imports' => $imports,
            'arguments' => $arguments,
        ]);

        $this->writeClassFile($definition['class'], $source);

        $output->writeln("Code generation finished:\n<comment>$source</comment>");
    }

    /**
     * Write source code to a file, given the class namespace
     *
     * @param string $service_ns
     * @param string $source
     */
    private function writeClassFile($service_ns, $source)
    {
        $root_dir = $this->getContainer()->getParameter('kernel.root_dir');
        $src_dir = realpath("$root_dir/../src");
        $file = str_replace('\\', '/', $service_ns) . '.php';
        $path = "$src_dir/$file";
        $dir = dirname($path);

        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $source);
    }

    /**
     * Get the namespace of a class reference, or the obvious interface it implements
     * For example: when the className is Router and it implements the RouterInterface
     * this method will return RouterInterface
     *
     * @param string $key
     * @return string
     */
    private function getNamespaceOrInterface($key)
    {
        $service = $this->getContainer()->get($key);
        $namespace = get_class($service);
        $argument_class = self::getClassName($namespace);

        // lookup specific namespace for class
        $interfaces = class_implements($service);
        foreach ($interfaces as $interface) {
            $interface_class = self::getClassName($interface);
            if ($interface_class === $argument_class . 'Interface') {
                $namespace = $interface;
            }
        }

        return $namespace;
    }

    /**
     * Return a class name given a full namespace
     *
     * @param $namespace
     * @return string
     */
    static function getClassName($namespace)
    {
        return substr(strrchr($namespace, '\\'), 1);
    }

    /**
     * Return the package name given a full namespace
     *
     * @param $namespace
     * @return string
     */
    static function getPackageName($namespace)
    {
        return substr($namespace, 0, strrpos($namespace, '\\'));
    }
}
