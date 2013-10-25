<?php
/**
 * ZendDiCompiler
 *
 * This source file is part of the ZendDiCompiler package
 *
 * @package    ZendDiCompiler
 * @license    New BSD License
 * @copyright  Copyright (c) 2013, aimfeld
 */

namespace ZendDiCompiler;

use Zend\Di\Di;
use Zend\Di\Definition\ArrayDefinition;
use Zend\Di\Definition\CompilerDefinition;
use Zend\Di\Definition\IntrospectionStrategy;
use Zend\Di\DefinitionList;
use Zend\Di\InstanceManager;
use Zend\Code\Scanner\DirectoryScanner;
use Zend\Config\Config;
use Zend\Di\Config as DiConfig;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\AbstractFactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\EventManager\GlobalEventManager;
use ZendDiCompiler\Exception\RuntimeException;
use ZendDiCompiler\Exception\RecoverException;


/**
 * Wrapper class for Zend\Di\Di
 *
 * Features:
 * - DI definition scanning and factory code generation
 * - Can deal with shared instances
 * - Can be used as a fallback abstract factory for Zend\ServiceManager
 * - Detects outdated generated code and automatic rescanning (great for development)
 * - Can create new instances or reuse instances created before
 *
 * @package    ZendDiCompiler
 */
class ZendDiCompiler implements AbstractFactoryInterface
{
    const GENERATED_SERVICE_LOCATOR = 'GeneratedServiceLocator';
    const TEMP_SERVICE_LOCATOR      = 'TempServiceLocator';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var object[]
     */
    protected $sharedInstances = array();

    /**
     * @var string[]
     */
    protected $typePreferences = array();

    /**
     * @var bool
     */
    protected $isInitialized = false;

    /**
     * @var GeneratedServiceLocator|TempServiceLocator
     */
    protected $generatedServiceLocator = null;

    /**
     * @var IntrospectionStrategy
     */
    protected $introspectionStrategy = null;

    /**
     * Add shared instances to be used by ZendDiCompiler.
     *
     * Typical things you want to add are e.g. a db adapter, the config, a session. These instances are
     * then constructor-injected by ZendDiCompiler. Call this the onBootstrap() method of your module class.
     *
     * @param array $sharedInstances ('MyModule\MyClass' => $instance)
     *
     * @throws RuntimeException
     */
    public function addSharedInstances(array $sharedInstances)
    {
        if ($this->isInitialized) {
            throw new RuntimeException(
                'Shared instances must be added before the onBootstrap() method of the ZendDiCompiler module is executed.
                Make sure your module is added *before* the ZendDiCompiler module.');
        }

        $this->sharedInstances = array_merge($this->sharedInstances, $sharedInstances);
    }

    /**
     * Use this to replace the standard introspection strategy of ZendDiCompiler
     *
     * Call this the onBootstrap() method of your module class.
     *
     * @param IntrospectionStrategy $introspectionStrategy
     */
    public function setIntrospectionStrategy(IntrospectionStrategy $introspectionStrategy)
    {
        $this->introspectionStrategy = $introspectionStrategy;
    }

    /**
     * @param string $name        The full class name (including namespace)
     * @param array  $params      A parameter array passed to the class constructor, if it has a array $params argument
     * @param bool   $newInstance If true, create a new instance every time (use as factory)
     *
     * @throws \Exception
     * @return null|object
     */
    public function get($name, array $params = array(), $newInstance = false)
    {
        $this->checkInit();

        $name = $this->getTypePreference($name);

        $instance = null;

        try {
            // Convert PHP errors to exception to catch errors due to changed constructors, etc.
            set_error_handler(array($this, 'exceptionErrorHandler'));
            $instance = $this->generatedServiceLocator->get($name, $params, $newInstance);

            if (!$instance) {
                $this->generatedServiceLocator = $this->reset(true);
                $instance                      = $this->generatedServiceLocator->get($name, $params, $newInstance);
            }
            restore_error_handler();
        } catch (RecoverException $e) {
            restore_error_handler();

            // Oops, maybe the class constructor has changed during development? Try with rescanned DI definitions.
            $this->generatedServiceLocator = $this->reset(true);
            $instance                      = $this->generatedServiceLocator->get($name, $params, $newInstance);
        } catch (\Exception $e) {
            restore_error_handler();
            throw $e;
        }

        return $instance;
    }

    /**
     * Is called by the ZendDiCompiler module itself.
     *
     * @param Config $config
     */
    public function setConfig(Config $config)
    {
        $this->config = $config;

        // Provide easy access to type preferences
        /** @var Config $typePreferences */
        $typePreferences       = $this->config->di->instance->preference;
        $this->typePreferences = $typePreferences->toArray();
    }

    /**
     * Set up DI definitions and create instances.
     *
     * Is called by the ZendDiCompiler module itself.
     */
    public function init(MvcEvent $mvcEvent)
    {
        $this->setDefaultSharedInstances($mvcEvent);

        $this->isInitialized = true;

        $fileName = realpath(
            sprintf(
                '%s/%s.php',
                $this->config->zendDiCompiler->writePath, self::GENERATED_SERVICE_LOCATOR
            )
        );
        if (file_exists($fileName)) {
            require_once $fileName;
            $serviceLocatorClass           = __NAMESPACE__ . '\\' . self::GENERATED_SERVICE_LOCATOR;
            $this->generatedServiceLocator = new $serviceLocatorClass;
            $this->setSharedInstances($this->generatedServiceLocator);
        } else {
            $this->generatedServiceLocator = $this->reset(false);
        }
    }

    /**
     * Determine if we can create a service with name.
     *
     * This function is called by the ServiceManager.
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @param string                  $name
     * @param string                  $requestedName
     *
     * @return bool
     */
    public function canCreateServiceWithName(ServiceLocatorInterface $serviceLocator, $name, $requestedName)
    {
        return true; // Yes, we can!
    }

    /**
     * Create service with name.
     *
     * This function is called by the ServiceManager.
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @param string                  $name
     * @param string                  $requestedName
     *
     * @return mixed
     */
    public function createServiceWithName(ServiceLocatorInterface $serviceLocator, $name, $requestedName)
    {
        return $this->get($requestedName);
    }

    /**
     * Returns class name or its substitute.
     *
     * @param string $className
     *
     * @return string
     */
    public function getTypePreference($className)
    {
        return array_key_exists($className, $this->typePreferences) ?
            $this->typePreferences[$className] : $className;
    }

    /**
     * @param MvcEvent $mvcEvent
     *
     * @return array
     */
    protected function getDefaultSharedInstances(MvcEvent $mvcEvent)
    {
        $application = $mvcEvent->getApplication();
        $serviceManager = $application->getServiceManager();

        return array(
            'Zend\Mvc\MvcEvent'                   => $mvcEvent,
            'Zend\Mvc\Application'                => $application,
            'Zend\ServiceManager\ServiceManager'  => $serviceManager,
            'Zend\EventManager\EventManager'      => GlobalEventManager::getEventCollection(),
            'Zend\Config\Config'                  => $this->config,
            'Zend\Mvc\Router\Http\TreeRouteStack' => $mvcEvent->getRouter(),
            'Zend\View\Renderer\PhpRenderer'      => $serviceManager->get('Zend\View\Renderer\PhpRenderer'),
            'ZendDiCompiler\DiFactory'                 => new DiFactory($this), // Provide DiFactory
            get_class($this)                      => $this, // Provide ZendDiCompiler itself
        );
    }

    /**
     * @param MvcEvent $mvcEvent
     *
     * @return array
     */
    protected function setDefaultSharedInstances(MvcEvent $mvcEvent)
    {
        foreach ($this->getDefaultSharedInstances($mvcEvent) as $class => $instance) {
            if (!array_key_exists($class, $this->sharedInstances)) {
                $this->sharedInstances[$class] = $instance;
            }
        }
    }

    /**
     * @return DefinitionList
     */
    protected function getDefinitionList()
    {
        // Compiling definitions can take a while.
        set_time_limit(1000);

        // Set up the directory scanner.
        $directoryScanner = new DirectoryScanner;
        foreach ($this->config->zendDiCompiler->scanDirectories as $directory) {
            $directoryScanner->addDirectory($directory);
        }

        // Compile definitions and convert them to an array.
        $introspectionStrategy = $this->getIntrospectionStrategy();
        $compilerDefinition    = new CompilerDefinition($introspectionStrategy);
        $compilerDefinition->setAllowReflectionExceptions(true);
        $compilerDefinition->addDirectoryScanner($directoryScanner);
        $compilerDefinition->compile();
        $definitionArray = $compilerDefinition->toArrayDefinition()->toArray();

        // Set up the DIC with compiled definitions.
        $definitionList     = new DefinitionList(array());
        $compiledDefinition = new ArrayDefinition($definitionArray);
        $definitionList->addDefinition($compiledDefinition);

        return $definitionList;
    }

    /**
     * Get introspection strategy (use only constructor injection by default)
     *
     * @return IntrospectionStrategy
     */
    protected function getIntrospectionStrategy()
    {
        if (!$this->introspectionStrategy) {
            $this->introspectionStrategy = new IntrospectionStrategy();
            $this->introspectionStrategy->setInterfaceInjectionInclusionPatterns(array());
            $this->introspectionStrategy->setMethodNameInclusionPatterns(array());
        }

        return $this->introspectionStrategy;
    }

    /**
     * @param InstanceManager|GeneratedServiceLocator|TempServiceLocator $object
     */
    protected function setSharedInstances($object)
    {
        /** @noinspection PhpUndefinedClassInspection */
        assert(
            $object instanceof InstanceManager ||
            $object instanceof GeneratedServiceLocator ||
            $object instanceof TempServiceLocator
        );

        if ($object instanceof InstanceManager) {
            foreach ($this->sharedInstances as $classOrAlias => $instance) {
                $object->addSharedInstance($instance, $classOrAlias);
            }
        } else {
            foreach ($this->sharedInstances as $classOrAlias => $instance) {
                /** @noinspection PhpUndefinedMethodInspection */
                $object->set($classOrAlias, $instance);
            }
        }
    }

    /**
     * @param bool $recoverFromOutdatedDefinitions
     *
     * @return GeneratedServiceLocator|TempServiceLocator
     */
    protected function generateServiceLocator($recoverFromOutdatedDefinitions)
    {
        // Setup Di
        $di       = new Di;
        $diConfig = new DiConfig($this->config->di);
        $di->configure($diConfig);
        $di->setDefinitionList($this->getDefinitionList());

        $this->setSharedInstances($di->instanceManager());

        $generator = new Generator($di, $this->config);

        list($fileName, $generatedClass) = $this->writeServiceLocator($generator, self::GENERATED_SERVICE_LOCATOR);

        if ($recoverFromOutdatedDefinitions) {
            // Within a php request, a class can only be loaded once. Therefore, we need a
            // temporary service locator class with updated definitions. The class
            // is used to create the service locator and then immediately deleted afterwards.
            // The next request uses the updated service locator class.
            list($fileName, $generatedClass) = $this->writeServiceLocator($generator, self::TEMP_SERVICE_LOCATOR);
            require_once $fileName;
            /** @var TempServiceLocator $serviceLocator */
            $serviceLocator = new $generatedClass;

            // Reuse previous shared instances.
            $serviceLocator->services = $this->generatedServiceLocator->services;
            unlink($fileName);
        } else {
            require_once $fileName;
            $serviceLocator = new $generatedClass;
        }

        return $serviceLocator;
    }

    /**
     * @param Generator $generator
     * @param string    $className Without namespace
     *
     * @return string[] Filename and full class name of the generated service locator class
     */
    protected function writeServiceLocator(Generator $generator, $className)
    {
        $generator->setNamespace(__NAMESPACE__);
        $generator->setContainerClass($className);
        $file     = $generator->getCodeGenerator();
        $path     = $this->config->zendDiCompiler->writePath;
        $fileName = $path . "/$className.php";
        $file->setFilename($fileName);
        $file->write();

        return array($fileName, __NAMESPACE__ . '\\' . $className);
    }

    /**
     * @param bool $recoverFromOutdatedDefinitions
     *
     * @return GeneratedServiceLocator|TempServiceLocator
     */
    protected function reset($recoverFromOutdatedDefinitions)
    {
        $generatedServiceLocator = $this->generateServiceLocator($recoverFromOutdatedDefinitions);
        $this->setSharedInstances($generatedServiceLocator);

        return $generatedServiceLocator;
    }

    /**
     * @throws RuntimeException
     */
    protected function checkInit()
    {
        if (!$this->isInitialized) {
            throw new RuntimeException(sprintf(
                '%s:init() must be called before instances can be retrieved.', get_class($this)
            ));
        }
    }

    /**
     * Convert PHP errors to exceptions.
     *
     * Instantiating classes with wrong constructor arguments results in an
     * E_RECOVERABLE_ERROR which is converted in a RecoverException.
     *
     * @see http://php.net/manual/en/class.errorexception.php
     *
     * @param $errno
     * @param $errstr
     * @param $errfile
     * @param $errline
     *
     * @return bool
     * @throws RecoverException
     */
    public function exceptionErrorHandler($errno, $errstr, $errfile, $errline)
    {
        if ($errno == E_RECOVERABLE_ERROR) {
            throw new RecoverException($errstr, 0, $errno, $errfile, $errline);
        }
        return;
    }
}