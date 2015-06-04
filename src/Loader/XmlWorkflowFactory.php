<?php
/**
 * @link    https://github.com/old-town/old-town-workflow
 * @author  Malofeykin Andrey  <and-rey2@yandex.ru>
 */
namespace OldTown\Workflow\Loader;


use OldTown\Workflow\Exception\FactoryException;
use OldTown\Workflow\Exception\InvalidWorkflowDescriptorException;
use OldTown\Workflow\Util\Properties\Properties;
use Serializable;
use SimpleXMLElement;
use OldTown\Workflow\Loader\XMLWorkflowFactory\WorkflowConfig;

/**
 * Class UrlWorkflowFactory
 *
 * @package OldTown\Workflow\Loader
 */
class  XmlWorkflowFactory extends AbstractWorkflowFactory implements Serializable
{
    /**
     * @var WorkflowConfig[]
     */
    protected $workflows;

    /**
     * @var bool
     */
    protected $reload = false;

    /**
     * Пути по умолчнаию до файла с workflows
     *
     * @var array
     */
    protected static $defaultPathsToWorkflows = [];

    /**
     * @param Properties $p
     */
    public function __construct(Properties $p = null)
    {
        parent::__construct($p);
        $this->initDefaultPathsToWorkflows();

    }

    /**
     * Иницализация путей по которым происходит поиск
     *
     * @return void
     */
    protected function initDefaultPathsToWorkflows()
    {
        static::$defaultPathsToWorkflows[] = __DIR__ . '/../../config';
    }


    /**
     * @param string $workflowName
     * @param object $layout
     *
     * @return $this
     */
    public function setLayout($workflowName, $layout)
    {

    }

    /**
     * @param string $workflowName
     *
     * @return object|null
     */
    public function getLayout($workflowName)
    {
        return null;
    }

    /**
     *
     * @return string
     */
    public function getName()
    {
        return '';
    }

    /**
     *
     * @return void
     * @throws FactoryException
     */
    public function initDone()
    {
        $this->reload = (boolean)$this->getProperties('reload', false);

        $name = $this->getProperties()->getProperty('resource', 'workflows.xml');

        $contentWorkflowFile = $this->getContentWorkflowFile($name);


        try {

            $xml = new SimpleXMLElement($contentWorkflowFile);

            $rootElements = $xml->xpath('/workflows');

            if (1 !== count($rootElements)) {
                $errMsg = "Нет корректного элемента workflows в файле {$name}";
                throw new FactoryException($errMsg);
            }
            $root = $rootElements[0];
            $this->workflows = [];

            $basedir = $this->getBaseDir($root);

            $list = $root->xpath('workflow');

            foreach ($list as $e) {
                $eAttribute = $e->attributes();
                if (!isset($eAttribute['type'])) {
                    $errMsg = 'У workflow отсутствует атрибут type';
                    throw new FactoryException($errMsg);
                }
                $type = (string)$eAttribute['type'];

                if (!isset($eAttribute['location'])) {
                    $errMsg = 'У workflow отсутствует атрибут location';
                    throw new FactoryException($errMsg);
                }
                $location = (string)$eAttribute['location'];

                $config = new WorkflowConfig($basedir, $type, $location);

                if (!isset($eAttribute['name'])) {
                    $errMsg = 'У workflow отсутствует атрибут name';
                    throw new FactoryException($errMsg);
                }
                $name = (string)$eAttribute['name'];

                $this->workflows[$name] = $config;

            }
        } catch (\Exception $e) {
            throw new InvalidWorkflowDescriptorException('Ошибка в конфигурации workflow', $e->getCode(), $e);
        }
    }


    /**
     * @param string $name
     * @param bool   $validate
     *
     * @return WorkflowDescriptor
     * @throws FactoryException
     */
    public function getWorkflow($name, $validate = true)
    {
        $name = (string)$name;
        if (!array_key_exists($name, $this->workflows)) {
            $errMsg = "Нет workflow с именем {$name}";
            throw new FactoryException($errMsg);
        }
        $c = $this->workflows[$name];

        if (!$c instanceof WorkflowConfig) {
            $errMsg = "Некорректный конфиг workflow  с именем";
            throw new FactoryException($errMsg);
        }

        if (null !== $c->descriptor) {
            if ($this->reload && (file_exists($c->url && (filemtime($c->url) > $c->lastModified)))) {
                $c->lastModified = filemtime($c->url);
                $this->loadWorkflow($c, $validate);
            }
        } else {
            $this->loadWorkflow($c, $validate);
        }

        $c->descriptor->setName($name);

        return $c->descriptor;
    }

    /**
     * @param WorkflowConfig $c
     * @param boolean        $validate
     *
     * @return void
     */
    private function loadWorkflow(WorkflowConfig $c, $validate)
    {
        $validate = (boolean)$validate;
        try {
            $c->descriptor = WorkflowLoader::load($c->url, $validate);
        } catch (\Exception $e) {
            $errMsg = "Некорректный дескрипторв workflow: {$c->url}";
            throw new FactoryException($errMsg, $e->getCode(), $e);
        }
    }

    /**
     * Возвращает абсолютный путь до директории где находится workflow xml файл
     *
     * @param SimpleXMLElement $root
     *
     * @return string
     */
    protected function getBaseDir(SimpleXMLElement $root)
    {
        $rootAttributes = $root->attributes();

        if (!isset($rootAttributes['basedir'])) {
            return null;
        }
        $basedir = $rootAttributes['basedir'];

        if (file_exists($basedir)) {
            $absolutePath = realpath($basedir);
        } else {
            $basedirResolve = $this->getProperties('user.dir', $basedir);
            $absolutePath = realpath($basedirResolve);
        }

        return $absolutePath;
    }

    /**
     * @param string $name
     *
     * @return boolean
     * @throws FactoryException
     */
    public function removeWorkflow($name)
    {
        throw new FactoryException('Удаление workflow не поддерживается');
    }

    /**
     * @param string $oldName
     * @param string $newName
     *
     * @return void
     */
    public function renameWorkflow($newName, $oldName = null)
    {

    }

    /**
     * @return void
     */
    public function save()
    {

    }


    /**
     * @param string $name
     *
     * @return string
     */
    protected function getContentWorkflowFile($name)
    {

        $paths = static::getDefaultPathsToWorkflows();

        $content = null;
        foreach ($paths as $path) {
            $path = realpath($path);
            if ($path) {
                $filePath = $path . DIRECTORY_SEPARATOR . $name;
                if (file_exists($filePath)) {
                    $content = file_get_contents($filePath);
                    break;
                }
            }
        }

        if (null === $content) {
            $errMsg = 'Не удалось прочитать конфигурационный файл';
            throw new FactoryException($errMsg);
        }

        return $content;
    }

    /**
     * @return array
     */
    public static function getDefaultPathsToWorkflows()
    {
        return self::$defaultPathsToWorkflows;
    }

    /**
     * @param array $defaultPathsToWorkflows
     */
    public static function setDefaultPathsToWorkflows(array $defaultPathsToWorkflows = [])
    {
        self::$defaultPathsToWorkflows = $defaultPathsToWorkflows;
    }

    /**
     * @param string $path
     */
    public static function addDefaultPathToWorkflows($path)
    {
        $path = (string)$path;

        array_unshift(self::$defaultPathsToWorkflows, $path);
    }

########################################################################################################################
#Необходимо портировать из базового приложения##########################################################################
########################################################################################################################

    /**
     * @param string $name
     *
     * @return boolean
     */
    public function isModifiable($name)
    {
        return false;
    }


    /**
     * String representation of object
     *
     * @link http://php.net/manual/en/serializable.serialize.php
     * @return string the string representation of the object or null
     */
    public function serialize()
    {

    }

    /**
     * Constructs the object
     *
     * @link http://php.net/manual/en/serializable.unserialize.php
     *
     * @param string $serialized <p>
     *
     * @return void
     */
    public function unserialize($serialized)
    {

    }


    /**
     *
     * @return String[]
     * @throws FactoryException
     */
    public function getWorkflowNames()
    {
        throw new FactoryException('URLWorkflowFactory не содержит имена workflow');
    }


    /**
     * @param string $name
     *
     * @return void
     * @throws FactoryException
     */
    public function createWorkflow($name)
    {

    }


    /**
     * Сохраняет workflow
     *
     * @param string             $name       имя workflow
     * @param WorkflowDescriptor $descriptor descriptor workflow
     * @param boolean            $replace    если true - то в случае существования одноименного workflow, оно будет
     *                                       заменено
     *
     * @return boolean true - если workflow было сохранено
     * @throws FactoryException
     * @throws InvalidWorkflowDescriptorException
     */
    public function saveWorkflow($name, WorkflowDescriptor $descriptor, $replace)
    {
        //@fixme Организовать созранение workflow в UrlWorkflowFactory
    }


}
