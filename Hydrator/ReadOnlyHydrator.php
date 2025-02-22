<?php

namespace steevanb\DoctrineReadOnlyHydrator\Hydrator;

use Doctrine\Common\Proxy\ProxyGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use steevanb\DoctrineReadOnlyHydrator\Entity\ReadOnlyEntityInterface;
use steevanb\DoctrineReadOnlyHydrator\Exception\PrivateMethodShouldNotAccessPropertiesException;

class ReadOnlyHydrator extends SimpleObjectHydrator
{
    public const HYDRATOR_NAME = 'readOnly';

    protected array $proxyFilePathsCache = [];
    protected array $proxyNamespacesCache = [];
    protected array $proxyClassNamesCache = [];
    protected ?string $proxyDirectoryCache = null;

    protected function createEntity(ClassMetadata $classMetaData, array $data): object
    {
        $className = $this->getEntityClassName($classMetaData, $data);
        $proxyFilePath = $this->generateProxyFile($classMetaData, $data);

        require_once($proxyFilePath);

        $proxyClassName = $this->getProxyNamespace($className) . '\\' . $this->getProxyClassName($className);
        $entity = new $proxyClassName(array_keys($data));

        $this->deferPostLoadInvoking($classMetaData, $entity);

        return $entity;
    }

    protected function generateProxyFile(ClassMetadata $classMetaData, array $data): string
    {
        $entityClassName = $this->getEntityClassName($classMetaData, $data);

        $proxyFilePath = $this->getProxyFilePath($entityClassName);
        if (file_exists($proxyFilePath)) {
            return $proxyFilePath;
        }

        $proxyMethodsCode = implode("\n\n", $this->getPhpForProxyMethods($classMetaData, $entityClassName));

        $proxyNamespace = $this->getProxyNamespace($entityClassName);
        $proxyClassName = $this->getProxyClassName($entityClassName);

        $generator = static::class;
        $readOnlyInterface = ReadOnlyEntityInterface::class;

        $php = <<<PHP
<?php

namespace $proxyNamespace;

/**
* DO NOT EDIT THIS FILE - IT WAS CREATED BY $generator
*/
class $proxyClassName extends \\$entityClassName implements \\$readOnlyInterface
{
protected \$loadedProperties;

public function __construct(array \$loadedProperties)
{
    \$this->loadedProperties = \$loadedProperties;
}

$proxyMethodsCode

public function isReadOnlyPropertiesLoaded(array \$properties)
{
    \$return = true;
    foreach (\$properties as \$property) {
        if (in_array(\$property, \$this->loadedProperties) === false) {
            \$return = false;
            break;
        }
    }

    return \$return;
}

public function assertReadOnlyPropertiesAreLoaded(array \$properties)
{
    foreach (\$properties as \$property) {
        if (in_array(\$property, \$this->loadedProperties) === false) {
            throw new \steevanb\DoctrineReadOnlyHydrator\Exception\PropertyNotLoadedException(\$this, \$property);
        }
    }
}
}
PHP;
        file_put_contents($proxyFilePath, $php);

        return $proxyFilePath;
    }

    public function getProxyFilePath(string $entityClassName): string
    {
        if (!isset($this->proxyFilePathsCache[$entityClassName])) {
            $fileName = str_replace('\\', '_', $entityClassName) . '.php';

            if($this->proxyDirectoryCache === null) {
                $this->proxyDirectoryCache = $this->getProxyDirectory();
            }

            $this->proxyFilePathsCache[$entityClassName] = $this->proxyDirectoryCache . DIRECTORY_SEPARATOR . $fileName;
        }

        return $this->proxyFilePathsCache[$entityClassName];
    }

    protected function getProxyNamespace(string $entityClassName): string
    {
        if (isset($this->proxyNamespacesCache[$entityClassName]) === false) {
            $this->proxyNamespacesCache[$entityClassName] = 'ReadOnlyProxies\\' . substr($entityClassName, 0, strrpos($entityClassName, '\\'));
        }

        return $this->proxyNamespacesCache[$entityClassName];
    }

    protected function getProxyClassName(string $entityClassName): string
    {
        if (isset($this->proxyClassNamesCache[$entityClassName]) === false) {
            $this->proxyClassNamesCache[$entityClassName] =
                substr($entityClassName, strrpos($entityClassName, '\\') + 1);
        }

        return $this->proxyClassNamesCache[$entityClassName];
    }

    /**
     * As Doctrine\ORM\EntityManager::newHydrator() call new FooHydrator($this), we can't set parameters to Hydrator.
     * So, we will use proxyDirectory from Doctrine\Common\Proxy\AbstractProxyFactory.
     * It's directory used by Doctrine\ORM\Internal\Hydration\ObjectHydrator.
     */
    protected function getProxyDirectory(): string
    {
        /** @var ProxyGenerator $proxyGenerator */
        $proxyGenerator = $this->getPrivatePropertyValue($this->_em->getProxyFactory(), 'proxyGenerator');
        $directory = $this->getPrivatePropertyValue($proxyGenerator, 'proxyDirectory');

        $readOnlyDirectory = $directory . DIRECTORY_SEPARATOR . 'ReadOnly';
        if (!is_dir($readOnlyDirectory)) {
            mkdir($readOnlyDirectory, 0775, true);
        }

        return $readOnlyDirectory;
    }

    /**
     * @param \ReflectionMethod $reflectionMethod
     * @param array $properties
     * @return array
     */
    protected function getUsedProperties(\ReflectionMethod $reflectionMethod, $properties)
    {
        $classLines = file($reflectionMethod->getFileName());
        $methodLines = array_slice(
            $classLines,
            $reflectionMethod->getStartLine() - 1,
            $reflectionMethod->getEndLine() - $reflectionMethod->getStartLine() + 1
        );
        $code = '<?php' . "\n" . implode("\n", $methodLines) . "\n" . '?>';

        $return = [];
        $nextStringIsProperty = false;
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_VARIABLE && $token[1] === '$this') {
                    $nextStringIsProperty = true;
                } elseif ($nextStringIsProperty && $token[0] === T_STRING) {
                    $nextStringIsProperty = false;
                    if (in_array($token[1], $properties)) {
                        $return[$token[1]] = true;
                    }
                }
            }
        }

        return array_keys($return);
    }

    protected function getPhpForProxyMethods(ClassMetadata $classMetaData, string $entityClassName): array
    {
        $return = [];
        $reflectionClass = new \ReflectionClass($entityClassName);
        $properties = array_merge($classMetaData->getFieldNames(), array_keys($classMetaData->associationMappings));
        foreach ($reflectionClass->getMethods() as $method) {
            if ($method->name === '__construct') {
                continue;
            }

            $usedProperties = $this->getUsedProperties($method, $properties);
            if (count($usedProperties) > 0) {
                if ($method->isPrivate()) {
                    throw new PrivateMethodShouldNotAccessPropertiesException(
                        $entityClassName,
                        $method->name,
                        $usedProperties
                    );
                }

                $return[] = $this->getPhpForMethod($method, $usedProperties);
            }
        }

        return $return;
    }

    /**
     * @param \ReflectionMethod $reflectionMethod
     * @param array $properties
     * @return string
     */
    protected function getPhpForMethod(\ReflectionMethod $reflectionMethod, array $properties)
    {
        $signature = ($reflectionMethod->isPublic()) ? 'public' : 'protected';
        $signature .= ' function ' . $reflectionMethod->name . '(';
        $parameters = [];
        foreach ($reflectionMethod->getParameters() as $parameter) {
            $parameters[] = $this->getPhpForParameter($parameter);
        }

        $signature .= implode(', ', $parameters) . ')';

        $method = $reflectionMethod->name;

        array_walk($properties, function(&$name) {
            $name = "'" . $name . "'";
        });
        $propertiesToAssert = implode(', ', $properties);

        if (
            version_compare(PHP_VERSION, '7.0.0', '>=')
            && $reflectionMethod->hasReturnType()
        ) {
            $signature .= ': ';

            if(version_compare(PHP_VERSION, '8.0.0', '>=') && $reflectionMethod->getReturnType() instanceof \ReflectionUnionType) {
                $types = $reflectionMethod->getReturnType()->getTypes();
            } else {
                $types = [$reflectionMethod->getReturnType()];
            }

            if (count($types) <= 1 && version_compare(PHP_VERSION, '7.1.0', '>=') && $reflectionMethod->getReturnType()->allowsNull()) {
                $signature .= '?';
            }

            $values = [];
            foreach ($types as $type) {
                if($type === null) {
                    $values[] = 'null';
                    continue;
                }

                if ($type->isBuiltin()) {
                    $type = static::extractNameFromReflexionType($type);
                } else {
                    switch (static::extractNameFromReflexionType($type)) {
                        case 'self':
                            $type = $this->getFullQualifiedClassName(
                                $reflectionMethod->getDeclaringClass()->getName()
                            );
                            break;
                        case 'parent':
                            throw new \Exception('Function with return type parent can\'t be overloaded.');
                        default:
                            $type = $this->getFullQualifiedClassName(
                                static::extractNameFromReflexionType($type)
                            );
                    }
                }

                $values[] = $type;
            }

            $returnKeyWord = (count($values) <= 1 && $values[0] === 'void') ? null : 'return ';
            $signature .= implode('|', $values);
        } else {
            $returnKeyWord = 'return ';
        }

        $php = <<<PHP
    $signature
    {
        \$this->assertReadOnlyPropertiesAreLoaded(array($propertiesToAssert));

        ${returnKeyWord}call_user_func_array(array('parent', '$method'), func_get_args());
    }
PHP;

        return $php;
    }

    /**
     * @param \ReflectionParameter $parameter
     * @return string
     * @throws \ReflectionException
     */
    protected function getPhpForParameter(\ReflectionParameter $parameter)
    {
        $types = $parameter->getType();
        if($types === null) {
            $types = [];
        } elseif(get_class($types) === "ReflectionUnionType") {
            $types = $types->getTypes();
        } else {
            $types = [$types];
        }

        $values = [];
        $hasNull = false;
        $needsNull = false;
        /** @var \ReflectionUnionType[]|\ReflectionType|null $type */
        foreach ($types as $type) {
            $php = $type->getName();
            if (
                version_compare(PHP_VERSION, '7.1.0', '>=')
                && $parameter->hasType()
                && $type->allowsNull()
            ) {
                $needsNull = true;
            }

            if ($type !== null && (class_exists($type->getName()) || interface_exists($type->getName()))) {
                $php = $this->getFullQualifiedClassName($type->getName()) ;
            }

            if($type->getName() === 'null') {
                $hasNull = true;
            }

            $values[] = $php;
        }

        $php = implode('|', $values).' ';
        if($needsNull && !$hasNull) {
            $php = '?'.$php;
        }

        if ($parameter->isPassedByReference()) {
            $php .= '&';
        }
        $php .= '$' . $parameter->name;

        if ($parameter->isDefaultValueAvailable()) {
            $parameterDefaultValue = $parameter->getDefaultValue();
            if ($parameter->isDefaultValueConstant()) {
                $defaultValue = $parameter->getDefaultValueConstantName();
            } elseif ($parameterDefaultValue === null) {
                $defaultValue = 'null';
            } elseif (is_bool($parameterDefaultValue)) {
                $defaultValue = ($parameterDefaultValue === true) ? 'true' : 'false';
            } elseif (is_string($parameterDefaultValue)) {
                $defaultValue = '\'' . $parameterDefaultValue . '\'';
            } elseif (is_array($parameterDefaultValue)) {
                $defaultValue = 'array()';
            } else {
                $defaultValue = $parameterDefaultValue;
            }
            $php .= ' = ' . $defaultValue;
        }

        return $php;
    }

    /**
     * PHP7 Reflection sometimes return \Foo\Bar, sometimes Foo\Bar
     * @param string $className
     * @return string
     */
    protected function getFullQualifiedClassName($className)
    {
        return '\\' . ltrim($className, '\\');
    }

    /**
     * @param \ReflectionType $reflectionType
     * @return string
     * @see https://github.com/symfony/symfony/blob/v3.2.6/src/Symfony/Component/PropertyInfo/Extractor/ReflectionExtractor.php#L215
     */
    protected static function extractNameFromReflexionType(\ReflectionType $reflectionType)
    {
        return $reflectionType instanceof \ReflectionNamedType
            ? $reflectionType->getName()
            : $reflectionType->__toString();
    }
}
