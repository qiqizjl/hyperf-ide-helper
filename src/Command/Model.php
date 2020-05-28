<?php

declare(strict_types=1);
/**
 *
 *
 * @author    耐小心 <i@naixiaoixn.com>
 * @time      2019/12/14 12:15 上午
 *
 * @copyright 2019 耐小心
 */

namespace Naixiaoxin\HyperfIdeHelper\Command;

use Hyperf\Database\Model\Relations\Relation;
use Hyperf\Utils\Filesystem\Filesystem;
use Hyperf\Utils\Str;
use ReflectionClass;
use Composer\Autoload\ClassMapGenerator;
use Hyperf\Command\Command;
use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Utils\ApplicationContext;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Tag;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;

class Model extends Command
{
    // CLI名称
    protected $name = "ide-helper:model";

    // 生成文件名称
    protected $filename = '_ide_helper_models.php';


    private $ignore = [];

    private   $dirs = ["app"];

    protected $write_model_magic_where = true;
    protected $properties = array();
    protected $methods = array();
    protected $write = false;
    protected $reset = true;
    protected $keep_text;
    private $container;
    private $config;

    public function __construct()
    {
        parent::__construct();
        $this->container = ApplicationContext::getContainer();
        $this->config    = $this->container->get(ConfigInterface::class);
    }

    /**
     * 执行CLI
     */
    public function handle()
    {
        $this->loadIgnore();

        $this->dateClass  = '\Carbon\Carbon';
        $content         = $this->generateDocs([]);
        $file = $this->container->get(Filesystem::class);
        $file->put($this->filename,$content);
    }


    protected function configure()
    {
        $this->addOption('ignore', "i", InputOption::VALUE_OPTIONAL, 'What prefix that you want the Model set.');

    }

    private function loadIgnore()
    {
        $ignore       = $this->getOption("ignore", "");
        $this->ignore = array_merge(
            explode(',', $ignore),
            $this->config->get("ide-helper.ignored_models", [])
        );
    }

    public function getOption($key, $default)
    {
        $result = $this->input->getOption($key);
        if ($result) {
            return $result;
        }
        return $default;
    }


    protected function generateDocs($loadModels)
    {


        $output = "<?php
// @formatter:off
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */
\n\n";

        $output .= \Naixiaoxin\HyperfIdeHelper\Eloquent::make();

        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');

        if (empty($loadModels)) {
            $models = $this->loadModels();
        } else {
            $models = array ();
            foreach ($loadModels as $model) {
                $models = array_merge($models, explode(',', $model));
            }
        }


        foreach ($models as $name) {
            if (in_array($name, $this->ignore)) {
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $this->comment("Ignoring model '$name'");
                }
                continue;
            }
            $this->properties = array ();
            $this->methods    = array ();
            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new ReflectionClass($name);

                    if (!$reflectionClass->isSubclassOf('Hyperf\DbConnection\Model\Model')) {
                        continue;
                    }

                    if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $this->comment("Loading model '$name'");
                    }

                    if (!$reflectionClass->IsInstantiable()) {
                        // ignore abstract class or interface
                        continue;
                    }
                    $model = $this->container->get($name);

                    if ($hasDoctrine) {
                        $this->getPropertiesFromTable($model);
                    }

                    if (method_exists($model, 'getCasts')) {
                        $this->castPropertiesType($model);
                    }

                    $this->getPropertiesFromMethods($model);
                    $this->getSoftDeleteMethods($model);
                    $output                .= $this->createPhpDocs($name);
                    $ignore[]              = $name;
                    $this->nullableColumns = [];
                } catch (\Throwable $e) {
                    $this->error("Exception: " . $e->getMessage() .
                                 "\nCould not analyze class $name.\n\nTrace:\n" .
                                 $e->getTraceAsString());
                }
            }
        }

        if (!$hasDoctrine) {
            $this->error(
                'Warning: `"doctrine/dbal": "~2.3"` is required to load database information. ' .
                'Please require that in your composer.json and run `composer update`.'
            );
        }

        return $output;
    }


    protected function loadModels()
    {
        $models = array ();
        foreach ($this->dirs as $dir) {
            $dir = BASE_PATH . '/' . $dir;
            if (file_exists($dir)) {
                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }
        return $models;
    }

    /**
     * cast the properties's type from $casts.
     *
     * @param \Hyperf\Database\Model\Model $model
     */
    protected function castPropertiesType($model)
    {
        $casts = $model->getCasts();
        foreach ($casts as $name => $type) {
            switch ($type) {
                case 'boolean':
                case 'bool':
                    $realType = 'boolean';
                    break;
                case 'string':
                    $realType = 'string';
                    break;
                case 'array':
                case 'json':
                    $realType = 'array';
                    break;
                case 'object':
                    $realType = 'object';
                    break;
                case 'int':
                case 'integer':
                case 'timestamp':
                    $realType = 'integer';
                    break;
                case 'real':
                case 'double':
                case 'float':
                    $realType = 'float';
                    break;
                case 'date':
                case 'datetime':
                    $realType = $this->dateClass;
                    break;
                case 'collection':
                    $realType = '\Hyperf\Utils\Collection';
                    break;
                default:
                    $realType = class_exists($type) ? ('\\' . $type) : 'mixed';
                    break;
            }

            if (!isset($this->properties[$name])) {
                continue;
            } else {
                $this->properties[$name]['type'] = $this->getTypeOverride($realType);

                if (isset($this->nullableColumns[$name])) {
                    $this->properties[$name]['type'] .= '|null';
                }
            }
        }
    }

    /**
     * Returns the overide type for the give type.
     *
     * @param string $type
     * @return string
     */
    protected function getTypeOverride($type)
    {
        $typeOverrides = $this->config->get('ide-helper.type_overrides', array ());

        return isset($typeOverrides[$type]) ? $typeOverrides[$type] : $type;
    }

    /**
     * Load the properties from the database table.
     *
     * @param \Hyperf\Database\Model\Model $model
     */
    protected function getPropertiesFromTable($model)
    {
        $table            = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema           = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

        $platformName = $databasePlatform->getName();
        $customTypes  = $this->config->get("ide-helper.custom_db_types.{$platformName}", array ());
        foreach ($customTypes as $yourTypeName => $doctrineTypeName) {
            $databasePlatform->registerDoctrineTypeMapping($yourTypeName, $doctrineTypeName);
        }

        $database = null;
        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        $columns = $schema->listTableColumns($table, $database);

        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();
                if (in_array($name, $model->getDates())) {
                    $type = $this->dateClass;
                } else {
                    $type = $column->getType()->getName();
                    switch ($type) {
                        case 'string':
                        case 'text':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                            $type = 'string';
                            break;
                        case 'integer':
                        case 'bigint':
                        case 'smallint':
                            $type = 'integer';
                            break;
                        case 'boolean':
                            $type = 'integer';
                            break;
                        case 'decimal':
                        case 'float':
                            $type = 'float';
                            break;
                        default:
                            $type = 'mixed';
                            break;
                    }
                }

                $comment = $column->getComment();
                if (!$column->getNotnull()) {
                    $this->nullableColumns[$name] = true;
                }
                $this->setProperty($name, $type, true, true, $comment, !$column->getNotnull());
                if ($this->write_model_magic_where) {
                    $this->setMethod(
                        Str::camel("where_" . $name),
                        '\Hyperf\Database\Model\Builder|\\' . get_class($model),
                        array ('$value')
                    );
                }
            }
        }
    }

    /**
     * @param \Hyperf\Database\Model\Model $model
     */
    protected function getPropertiesFromMethods($model)
    {
        $methods = get_class_methods($model);
        if ($methods) {
            sort($methods);
            foreach ($methods as $method) {
                if (Str::startsWith($method, 'get') && Str::endsWith(
                        $method,
                        'Attribute'
                    ) && $method !== 'getAttribute'
                ) {
                    //Magic get<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $reflection = new \ReflectionMethod($model, $method);
                        $type       = $this->getReturnTypeFromDocBlock($reflection);
                        $this->setProperty($name, $type, true, null);
                    }
                } elseif (Str::startsWith($method, 'set') && Str::endsWith(
                        $method,
                        'Attribute'
                    ) && $method !== 'setAttribute'
                ) {
                    //Magic set<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $this->setProperty($name, null, null, true);
                    }
                } elseif (Str::startsWith($method, 'scope') && $method !== 'scopeQuery') {
                    //Magic set<name>Attribute
                    $name = Str::camel(substr($method, 5));
                    if (!empty($name)) {
                        $reflection = new \ReflectionMethod($model, $method);
                        $args       = $this->getParameters($reflection);
                        //Remove the first ($query) argument
                        array_shift($args);
                        $this->setMethod($name, '\Hyperf\Database\Model\Builder|\\' . $reflection->class, $args);
                    }
                } elseif (in_array($method, ['query', 'newQuery', 'newModelQuery'])) {
                    $reflection = new \ReflectionClass($model);

                    $builder = get_class($model->newModelQuery());

                    $this->setMethod($method, "\\{$builder}|\\" . $reflection->getName());
                } elseif (!method_exists('Hyperf\DbConnection\Model\Model', $method)
                          && !Str::startsWith($method, 'get')
                ) {
                    $reflection = new \ReflectionMethod($model, $method);

                    if ($returnType = $reflection->getReturnType()) {
                        $type = $returnType instanceof \ReflectionNamedType
                            ? $returnType->getName()
                            : (string)$returnType;
                    } else {
                        // php 7.x type or fallback to docblock
                        $type = (string)$this->getReturnTypeFromDocBlock($reflection);
                    }

                    $file = new \SplFileObject($reflection->getFileName());
                    $file->seek($reflection->getStartLine() - 1);

                    $code = '';
                    while ($file->key() < $reflection->getEndLine()) {
                        $code .= $file->current();
                        $file->next();
                    }
                    $code  = trim(preg_replace('/\s\s+/', '', $code));
                    $begin = strpos($code, 'function');

                    $code  = substr($code, $begin, strrpos($code, '}') - $begin + 1);

                    foreach (array (
                                 'hasMany'        => '\Hyperf\Database\Model\Relations\HasMany',
                                 'hasManyThrough' => '\Hyperf\Database\Model\Relations\HasManyThrough',
                                 'hasOneThrough'  => '\Hyperf\Database\Model\Relations\HasOneThrough',
                                 'belongsToMany'  => '\Hyperf\Database\Model\Relations\BelongsToMany',
                                 'hasOne'         => '\Hyperf\Database\Model\Relations\HasOne',
                                 'belongsTo'      => '\Hyperf\Database\Model\Relations\BelongsTo',
                                 'morphOne'       => '\Hyperf\Database\Model\Relations\MorphOne',
                                 'morphTo'        => '\Hyperf\Database\Model\Relations\MorphTo',
                                 'morphMany'      => '\Hyperf\Database\Model\Relations\MorphMany',
                                 'morphToMany'    => '\Hyperf\Database\Model\Relations\MorphToMany',
                                 'morphedByMany'  => '\Hyperf\Database\Model\Relations\MorphToMany',
                             ) as $relation => $impl) {
                        $search = '$this->' . $relation . '(';
                        if (stripos($code, $search) || ltrim($impl, '\\') === ltrim((string)$type, '\\')) {
                            //Resolve the relation's model to a Relation object.
                            $methodReflection = new \ReflectionMethod($model, $method);
                            if ($methodReflection->getNumberOfParameters()) {
                                continue;
                            }

                            // Adding constraints requires reading model properties which
                            // can cause errors. Since we don't need constraints we can
                            // disable them when we fetch the relation to avoid errors.
                            $relationObj = Relation::noConstraints(function () use ($model, $method) {
                                return $model->$method();
                            });

                            if ($relationObj instanceof Relation) {
                                $relatedModel = '\\' . get_class($relationObj->getRelated());

                                $relations = [
                                    'hasManyThrough',
                                    'belongsToMany',
                                    'hasMany',
                                    'morphMany',
                                    'morphToMany',
                                    'morphedByMany',
                                ];
                                if (strpos(get_class($relationObj), 'Many') !== false) {
                                    //Collection or array of models (because Collection is Arrayable)
                                    $this->setProperty(
                                        $method,
                                        $this->getCollectionClass($relatedModel) . '|' . $relatedModel . '[]',
                                        true,
                                        null
                                    );
                                    $this->setProperty(
                                        Str::snake($method) . '_count',
                                        'int|null',
                                        true,
                                        false
                                    );
                                } elseif ($relation === "morphTo") {
                                    // Model isn't specified because relation is polymorphic
                                    $this->setProperty(
                                        $method,
                                        '\Hyperf\DbConnection\Model\Model|\Eloquent',
                                        true,
                                        null
                                    );
                                } else {
                                    //Single model is returned
                                    $this->setProperty(
                                        $method,
                                        $relatedModel,
                                        true,
                                        null,
                                        '',
                                        $this->isRelationForeignKeyNullable($relationObj)
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Check if the foreign key of the relation is nullable
     *
     * @param Relation $relation
     *
     * @return bool
     */
    private function isRelationForeignKeyNullable(Relation $relation)
    {
        $reflectionObj = new \ReflectionObject($relation);
        if (!$reflectionObj->hasProperty('foreignKey')) {
            return false;
        }
        $fkProp = $reflectionObj->getProperty('foreignKey');
        $fkProp->setAccessible(true);

        return isset($this->nullableColumns[$fkProp->getValue($relation)]);
    }

    /**
     * @param string      $name
     * @param string|null $type
     * @param bool|null   $read
     * @param bool|null   $write
     * @param string|null $comment
     * @param bool        $nullable
     */
    protected function setProperty($name, $type = null, $read = null, $write = null, $comment = '', $nullable = false)
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name]            = array ();
            $this->properties[$name]['type']    = 'mixed';
            $this->properties[$name]['read']    = false;
            $this->properties[$name]['write']   = false;
            $this->properties[$name]['comment'] = (string)$comment;
        }
        if ($type !== null) {
            $newType = $this->getTypeOverride($type);
            if ($nullable) {
                $newType .= '|null';
            }
            $this->properties[$name]['type'] = $newType;
        }
        if ($read !== null) {
            $this->properties[$name]['read'] = $read;
        }
        if ($write !== null) {
            $this->properties[$name]['write'] = $write;
        }
    }

    protected function setMethod($name, $type = '', $arguments = array ())
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);

        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name]              = array ();
            $this->methods[$name]['type']      = $type;
            $this->methods[$name]['arguments'] = $arguments;
        }
    }

    /**
     * @param string $class
     * @return string
     */
    protected function createPhpDocs($class)
    {

        $reflection  = new ReflectionClass($class);
        $namespace   = $reflection->getNamespaceName();
        $classname   = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();
        $keyword     = $this->getClassKeyword($reflection);

        if ($this->reset) {
            $phpdoc = new DocBlock('', new Context($namespace));
            if ($this->keep_text) {
                $phpdoc->setText(
                    (new DocBlock($reflection, new Context($namespace)))->getText()
                );
            }
        } else {
            $phpdoc = new DocBlock($reflection, new Context($namespace));
        }

        if (!$phpdoc->getText()) {
            $phpdoc->setText($class);
        }

        $properties = array ();
        $methods    = array ();
        foreach ($phpdoc->getTags() as $tag) {
            $name = $tag->getName();
            if ($name == "property" || $name == "property-read" || $name == "property-write") {
                $properties[] = $tag->getVariableName();
            } elseif ($name == "method") {
                $methods[] = $tag->getMethodName();
            }
        }

        foreach ($this->properties as $name => $property) {
            $name = "\$$name";

            if ($this->hasCamelCaseModelProperties()) {
                $name = Str::camel($name);
            }

            if (in_array($name, $properties)) {
                continue;
            }
            if ($property['read'] && $property['write']) {
                $attr = 'property';
            } elseif ($property['write']) {
                $attr = 'property-write';
            } else {
                $attr = 'property-read';
            }

            $tagLine = trim("@{$attr} {$property['type']} {$name} {$property['comment']}");
            $tag     = Tag::createInstance($tagLine, $phpdoc);
            $phpdoc->appendTag($tag);
        }

        ksort($this->methods);

        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }
            $arguments = implode(', ', $method['arguments']);
            $tag       = Tag::createInstance("@method static {$method['type']} {$name}({$arguments})", $phpdoc);
            $phpdoc->appendTag($tag);
        }

        if ($this->write && !$phpdoc->getTagsByName('mixin')) {
            $phpdoc->appendTag(Tag::createInstance("@mixin \\Eloquent", $phpdoc));
        }

        $serializer = new DocBlockSerializer();
        $serializer->getDocComment($phpdoc);
        $docComment = $serializer->getDocComment($phpdoc);


        if ($this->write) {
            $filename = $reflection->getFileName();
            $contents = $this->files->get($filename);
            if ($originalDoc) {
                $contents = str_replace($originalDoc, $docComment, $contents);
            } else {
                $needle  = "class {$classname}";
                $replace = "{$docComment}\nclass {$classname}";
                $pos     = strpos($contents, $needle);
                if ($pos !== false) {
                    $contents = substr_replace($contents, $replace, $pos, strlen($needle));
                }
            }
            if ($this->files->put($filename, $contents)) {
                $this->info('Written new phpDocBlock to ' . $filename);
            }
        }

        $output = "namespace {$namespace}{\n{$docComment}\n\t{$keyword}class {$classname} extends \Eloquent {}\n}\n\n";
        return $output;
    }

    /**
     * Get the parameters and format them correctly
     *
     * @param $method
     * @return array
     */
    public function getParameters($method)
    {
        //Loop through the default values for paremeters, and make the correct output string
        $params            = array ();
        $paramsWithDefault = array ();
        /** @var \ReflectionParameter $param */
        foreach ($method->getParameters() as $param) {
            $paramClass = $param->getClass();
            $paramStr   = (!is_null($paramClass) ? '\\' . $paramClass->getName() . ' ' : '') . '$' . $param->getName();
            $params[]   = $paramStr;
            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                } elseif (is_array($default)) {
                    $default = '[]';
                } elseif (is_null($default)) {
                    $default = 'null';
                } elseif (is_int($default)) {
                    //$default = $default;
                } else {
                    $default = "'" . trim($default) . "'";
                }
                $paramStr .= " = $default";
            }
            $paramsWithDefault[] = $paramStr;
        }
        return $paramsWithDefault;
    }

    /**
     * Determine a model classes' collection type.
     *
     * @see http://laravel.com/docs/eloquent-collections#custom-collections
     * @param string $className
     * @return string
     */
    private function getCollectionClass($className)
    {
        // Return something in the very very unlikely scenario the model doesn't
        // have a newCollection() method.
        if (!method_exists($className, 'newCollection')) {
            return '\Hyperf\Database\Model\Collection';
        }

        /** @var \Hyperf\Database\Model\Model $model */
        $model = new $className;
        return '\\' . get_class($model->newCollection());
    }

    /**
     * @return bool
     */
    protected function hasCamelCaseModelProperties()
    {
        return $this->config->get('ide-helper.model_camel_case_properties', false);
    }

    /**
     * Get method return type based on it DocBlock comment
     *
     * @param \ReflectionMethod $reflection
     *
     * @return null|string
     */
    protected function getReturnTypeFromDocBlock(\ReflectionMethod $reflection)
    {
        $type   = null;
        $phpdoc = new DocBlock($reflection);

        if ($phpdoc->hasTag('return')) {
            $type = $phpdoc->getTagsByName('return')[0]->getType();
        }

        return $type;
    }

    /**
     * Generates methods provided by the SoftDeletes trait
     *
     * @param \Hyperf\Database\Model\Model $model
     */
    protected function getSoftDeleteMethods($model)
    {
        $traits = class_uses(get_class($model), true);
        if (in_array('Hyperf\\Database\\Model\\SoftDeletes', $traits)) {
            $this->setMethod('forceDelete', 'bool|null', []);
            $this->setMethod('restore', 'bool|null', []);

            $this->setMethod('withTrashed', '\Hyperf\Database\Model\Builder|\\' . get_class($model), []);
            $this->setMethod('withoutTrashed', '\Hyperf\Database\Model\Builder|\\' . get_class($model), []);
            $this->setMethod('onlyTrashed', '\Hyperf\Database\Model\Builder|\\' . get_class($model), []);
        }
    }

    /**
     * @param ReflectionClass $reflection
     * @return string
     */
    private function getClassKeyword(ReflectionClass $reflection)
    {
        if ($reflection->isFinal()) {
            $keyword = 'final ';
        } elseif ($reflection->isAbstract()) {
            $keyword = 'abstract ';
        } else {
            $keyword = '';
        }

        return $keyword;
    }
}