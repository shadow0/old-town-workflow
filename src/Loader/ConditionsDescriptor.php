<?php
/**
 * @link https://github.com/old-town/old-town-workflow
 * @author  Malofeykin Andrey  <and-rey2@yandex.ru>
 */
namespace OldTown\Workflow\Loader;

use DOMElement;
use DOMNode;
use OldTown\Workflow\Exception\InternalWorkflowException;
use OldTown\Workflow\Exception\InvalidDescriptorException;
use OldTown\Workflow\Exception\InvalidWorkflowDescriptorException;
use SplObjectStorage;
use DOMDocument;


/**
 * Interface WorkflowDescriptor
 *
 * @package OldTown\Workflow\Loader
 */
class ConditionsDescriptor extends AbstractDescriptor
    implements
        Traits\TypeInterface,
        WriteXmlInterface,
        ValidateDescriptorInterface
{
    use Traits\TypeTrait;

    /**
     * @var ConditionsDescriptor[]|SplObjectStorage
     */
    private $conditions;

    /**
     * @param $element
     */
    public function __construct(DOMElement $element = null)
    {
        $this->conditions = new SplObjectStorage();

        parent::__construct($element);

        if (null !== $element) {
            $this->init($element);
        }
    }

    /**
     * @param DOMElement $element
     *
     * @return void
     */
    protected function init(DOMElement $element)
    {
        $this->parseType($element, false);

        for ($i = 0; $i < $element->childNodes->length; $i++) {
            /** @var DOMElement $child */
            $child = $element->childNodes->item($i);

            if ($child instanceof DOMNode) {
                if ('condition' === $child->nodeName) {
                    $condition = DescriptorFactory::getFactory()->createConditionDescriptor($child);
                    $this->conditions->attach($condition);
                } elseif ('conditions' === $child->nodeName) {
                    $condition = DescriptorFactory::getFactory()->createConditionsDescriptor($child);
                    $this->conditions->attach($condition);
                }
            }
        }
    }

    /**
     * @return ConditionsDescriptor[]|SplObjectStorage
     */
    public function getConditions()
    {
        return $this->conditions;
    }

    /**
     * Создает DOMElement - эквивалентный состоянию дескриптора
     *
     * @param DOMDocument $dom
     *
     * @return DOMElement|null
     * @throws InvalidDescriptorException
     */
    public function writeXml(DOMDocument $dom = null)
    {
        $countConditions = $this->getConditions()->count();

        if ($countConditions > 0) {
            $descriptor = $dom->createElement('conditions');

            if ($countConditions > 1) {
                $type = $this->getType();
                if (null === $type) {
                    $errMsg = 'Некорректное значение для атрибута type';
                    throw new InvalidDescriptorException($errMsg);
                }
                $descriptor->setAttribute('type', $type);
            }

            foreach ($this->getConditions() as $condition) {
                $conditionDescriptor = $condition->writeXml($dom);
                $descriptor->appendChild($conditionDescriptor);
            }

            return $descriptor;
        }

        return null;
    }

    /**
     * Валидация дескриптора
     *
     * @return void
     * @throws InvalidWorkflowDescriptorException
     * @throws InternalWorkflowException
     */
    public function validate()
    {
        $conditions = $this->getConditions();
        ValidationHelper::validate($conditions);


        $countConditions = $conditions->count();

        if ($countConditions === 0) {
            $desc = $this->getParent();
            if ($desc instanceof ConditionalResultDescriptor) {
                $destination = $desc->getDestination();

                $parentConditionalResult = $desc->getParent();
                $from = null;
                if (method_exists($parentConditionalResult, 'getName')) {
                    $from = call_user_func([$parentConditionalResult, 'getName']);
                }
                if (!$from) {
                    $from = get_class($parentConditionalResult);
                }


                $errMsg = sprintf(
                    'Действие %s ведущее на шаг %s, должно иметь не менее одного условия в блоке result',
                    $from,
                    $destination
                );

                throw new InvalidWorkflowDescriptorException($errMsg);
            }
        }

        if ($countConditions > 1 && null === $this->getType()) {
            $errMsg = 'В условие должен быть определен тип AND или OR';
            throw new InvalidWorkflowDescriptorException($errMsg);
        }
    }
}
