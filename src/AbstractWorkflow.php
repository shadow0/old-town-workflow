<?php
/**
 * @link https://github.com/old-town/old-town-workflow
 * @author  Malofeykin Andrey  <and-rey2@yandex.ru>
 */
namespace OldTown\Workflow;

use OldTown\Log\LogFactory;
use OldTown\PropertySet\PropertySetInterface;
use OldTown\PropertySet\PropertySetManager;
use OldTown\Workflow\Config\ConfigurationInterface;
use OldTown\Workflow\Config\DefaultConfiguration;
use OldTown\Workflow\Exception\FactoryException;
use OldTown\Workflow\Exception\InternalWorkflowException;
use OldTown\Workflow\Exception\InvalidActionException;
use OldTown\Workflow\Exception\InvalidArgumentException;
use OldTown\Workflow\Exception\InvalidEntryStateException;
use OldTown\Workflow\Exception\InvalidInputException;
use OldTown\Workflow\Exception\InvalidRoleException;
use OldTown\Workflow\Exception\StoreException;
use OldTown\Workflow\Exception\WorkflowException;
use OldTown\Workflow\Loader\ActionDescriptor;
use OldTown\Workflow\Loader\ConditionDescriptor;
use OldTown\Workflow\Loader\ConditionsDescriptor;
use OldTown\Workflow\Loader\FunctionDescriptor;
use OldTown\Workflow\Loader\RegisterDescriptor;
use OldTown\Workflow\Loader\ResultDescriptor;
use OldTown\Workflow\Loader\ValidatorDescriptor;
use OldTown\Workflow\Loader\WorkflowDescriptor;
use OldTown\Workflow\Query\WorkflowExpressionQuery;
use OldTown\Workflow\Spi\SimpleWorkflowEntry;
use OldTown\Workflow\Spi\StepInterface;
use OldTown\Workflow\Spi\WorkflowEntryInterface;
use OldTown\Workflow\Spi\WorkflowStoreInterface;
use Psr\Log\LoggerInterface;
use Traversable;
use SplObjectStorage;
use DateTime;


/**
 * Class AbstractWorkflow
 *
 * @package OldTown\Workflow
 */
abstract class  AbstractWorkflow implements WorkflowInterface
{
    /**
     * @var WorkflowContextInterface
     */
    protected $context;

    /**
     * @var ConfigurationInterface
     */
    private $configuration;

    /**
     *
     * @var array
     */
    private $stateCache;

    /**
     * @var TypeResolver
     */
    private $typeResolver;

    /**
     * Логер
     *
     * @var LoggerInterface
     */
    protected $log;

    /**
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function __construct()
    {
        try {
            $this->log = LogFactory::getLog();
        } catch (\Exception $e) {
            $errMsg = 'Ошибка при инициализации подсистемы логирования';
            throw new InternalWorkflowException($errMsg);
        }
    }

    /**
     * Переход между двумя статусами
     *
     * @param WorkflowEntryInterface $entry
     * @param StepInterface[] $currentSteps
     * @param WorkflowStoreInterface $store
     * @param WorkflowDescriptor $wf
     * @param ActionDescriptor $action
     * @param array $transientVars
     * @param array $inputs
     * @param PropertySetInterface $ps
     *
     * @return boolean
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InvalidEntryStateException
     * @throws \OldTown\Workflow\Exception\InvalidInputException
     * @throws \OldTown\Workflow\Exception\FactoryException
     * @throws \OldTown\Workflow\Exception\InvalidActionException
     */
    protected function transitionWorkflow(WorkflowEntryInterface $entry, $currentSteps, WorkflowStoreInterface $store, WorkflowDescriptor $wf, ActionDescriptor $action, &$transientVars, $inputs, PropertySetInterface $ps)
    {
        $this->stateCache = null;

        $step = $this->getCurrentStep($wf, $action->getId(), $currentSteps, $transientVars, $ps);

        $validators = $action->getValidators();
        if ($validators->count() > 0) {
            $this->verifyInputs($entry, $validators, $transientVars, $ps);
        }


        if (null !== $step) {
            $stepPostFunctions = $wf->getStep($step->getStepId())->getPostFunctions();
            foreach ($stepPostFunctions as $function) {
                $this->executeFunction($function, $transientVars, $ps);
            }
        }

        $conditionalResults = $action->getConditionalResults();
        $extraPreFunctions = null;
        $extraPostFunctions = null;

        $theResults = [];
        $theResults[0] = null;


        $currentStepId = null !== $step ? $step->getStepId()  : -1;
        foreach ($conditionalResults as $conditionalResult) {
            if ($this->passesConditions(null, $conditionalResult->getConditions(), $transientVars, $ps, $currentStepId)) {
                $theResults[0] = $conditionalResult;

                $validatorsStorage = $conditionalResult->getValidators();
                if ($validatorsStorage->count() > 0) {
                    $this->verifyInputs($entry, $validatorsStorage, $transientVars, $ps);
                }

                $extraPreFunctions = $conditionalResult->getPreFunctions();
                $extraPostFunctions = $conditionalResult->getPostFunctions();

                break;
            }
        }


        if (null ===  $theResults[0]) {
            $theResults[0] = $action->getUnconditionalResult();
            $this->verifyInputs($entry, $theResults[0]->getValidators(), $transientVars, $ps);
            $extraPreFunctions = $theResults[0]->getPreFunctions();
            $extraPostFunctions = $theResults[0]->getPostFunctions();
        }

        $logMsg = sprintf('theResult=%s %s', $theResults[0]->getStep(), $theResults[0]->getStatus());
        $this->getLog()->debug($logMsg);


        if ($extraPreFunctions && $extraPreFunctions->count() > 0) {
            foreach ($extraPreFunctions as $function) {
                $this->executeFunction($function, $transientVars, $ps);
            }
        }

        $split = $theResults[0]->getSplit();
        $join = $theResults[0]->getJoin();
        if (null !== $split && 0 !== $split) {
            $splitDesc = $wf->getSplit($split);
            $results = $splitDesc->getResults();
            $splitPreFunctions = [];
            $splitPostFunctions = [];

            foreach ($results as $resultDescriptor) {
                if ($resultDescriptor->getValidators()->count() > 0) {
                    $this->verifyInputs($entry, $resultDescriptor->getValidators(), $transientVars, $ps);
                }

                foreach ($resultDescriptor->getPreFunctions() as $function) {
                    $splitPreFunctions[] = $function;
                }
                foreach ($resultDescriptor->getPostFunctions() as $function) {
                    $splitPostFunctions[] = $function;
                }
            }

            foreach ($splitPreFunctions as $function) {
                $this->executeFunction($function, $transientVars, $ps);
            }

            if (!$action->isFinish()) {
                $moveFirst = true;

                $theResults = [];
                foreach ($results as $result) {
                    $theResults[] = $result;
                }

                foreach ($results as $resultDescriptor) {
                    $moveToHistoryStep = null;

                    if ($moveFirst) {
                        $moveToHistoryStep = $step;
                    }

                    $previousIds = [];

                    if (null !== $step) {
                        $previousIds[] = $step->getStepId();
                    }

                    $this->createNewCurrentStep($resultDescriptor, $entry, $store, $action->getId(), $moveToHistoryStep, $previousIds, $transientVars, $ps);
                    $moveFirst = false;
                }
            }


            foreach ($splitPostFunctions as $function) {
                $this->executeFunction($function, $transientVars, $ps);
            }
        } elseif (null !== $join && 0 !== $join) {
            $joinDesc = $wf->getJoin($join);
            $oldStatus = $theResults[0]->getOldStatus();
            $caller = $this->context->getCaller();
            $step = $store->markFinished($step, $action->getId(), new DateTime(), $oldStatus, $caller);

            $store->moveToHistory($step);

            /** @var StepInterface[] $joinSteps */
            $joinSteps = [];
            $joinSteps[] = $step;

            foreach ($currentSteps as $currentStep) {
                if ($currentStep->getId() !== $step->getId()) {
                    $stepDesc = $wf->getStep($currentStep->getStepId());

                    if ($stepDesc->resultsInJoin($join)) {
                        $joinSteps[] = $currentSteps;
                    }
                }
            }

            $historySteps = $store->findHistorySteps($entry->getId());

            foreach ($historySteps as $historyStep) {
                if ($historyStep->getId() !== $step->getId()) {
                    $stepDesc = $wf->getStep($historyStep->getStepId());

                    if ($stepDesc->resultsInJoin($join)) {
                        $joinSteps[] = $currentSteps;
                    }
                }
            }

            $jn = new JoinNodes($joinSteps);
            $transientVars['jn'] = $jn;


            if ($this->passesConditions(null, $joinDesc->getConditions(), $transientVars, $ps, 0)) {
                $joinResult = $joinDesc->getResult();

                $joinResultValidators = $joinResult->getValidators();
                if ($joinResultValidators->count() > 0) {
                    $this->verifyInputs($entry, $joinResultValidators, $transientVars, $ps);
                }

                foreach ($joinResult->getPreFunctions() as $function) {
                    $this->executeFunction($function, $transientVars, $ps);
                }

                $previousIds = [];
                $i = 1;

                foreach ($joinSteps as  $currentStep) {
                    if (!$historySteps->contains($currentStep) && $currentStep->getId() !== $step->getId()) {
                        $store->moveToHistory($step);
                    }

                    $previousIds[$i] = $currentStep->getId();
                }

                if (!$action->isFinish()) {
                    $previousIds[0] = $step->getId();
                    $theResults[0] = $joinDesc->getResult();

                    $this->createNewCurrentStep($joinDesc->getResult(), $entry, $store, $action->getId(), null, $previousIds, $transientVars, $ps);
                }

                foreach ($joinResult->getPostFunctions() as $function) {
                    $this->executeFunction($function, $transientVars, $ps);
                }
            }
        } else {
            $previousIds = [];

            if (null !== $step) {
                $previousIds[] = $step->getStepId();
            }

            if (!$action->isFinish()) {
                $this->createNewCurrentStep($theResults[0], $entry, $store, $action->getId(), $step, $previousIds, $transientVars, $ps);
            }
        }

        if ($extraPostFunctions && $extraPostFunctions->count() > 0) {
            foreach ($extraPostFunctions as $function) {
                $this->executeFunction($function, $transientVars, $ps);
            }
        }

        if (WorkflowEntryInterface::COMPLETED !== $entry->getState() && null !== $wf->getInitialAction($action->getId())) {
            $this->changeEntryState($entry->getId(), WorkflowEntryInterface::ACTIVATED);
        }

        if (!$action->isFinish()) {
            $this->completeEntry($action, $entry->getId(), $this->getCurrentSteps($entry->getId()), WorkflowEntryInterface::COMPLETED);
            return true;
        }

        $availableAutoActions = $this->getAvailableAutoActions($entry->getId(), $inputs);

        if (count($availableAutoActions) > 0) {
            $this->doAction($entry->getId(), $availableAutoActions[0], $inputs);
        }

        return false;
    }


    /**
     * @param       $id
     * @param array $inputs
     *
     * @return array
     * @throws \OldTown\Workflow\Exception\WorkflowException
     */
    protected function getAvailableAutoActions($id, array $inputs = [])
    {
        try {
            $store = $this->getPersistence();
            $entry = $store->findEntry($id);

            if (null === $entry) {
                $errMsg = sprintf(
                    'Нет сущности workflow c id %s',
                    $id
                );
                throw new InvalidArgumentException($errMsg);
            }


            if (WorkflowEntryInterface::ACTIVATED !== $entry->getState()) {
                $logMsg = sprintf('--> состояние %s', $entry->getState());
                $this->getLog()->debug($logMsg);
                return [0];
            }

            $wf = $this->getConfiguration()->getWorkflow($entry->getWorkflowName());

            if (null === $wf) {
                $errMsg = sprintf(
                    'Нет workflow c именем %s',
                    $entry->getWorkflowName()
                );
                throw new InvalidArgumentException($errMsg);
            }

            $l = [];
            $ps = $store->getPropertySet($id);
            $transientVars = (null === $inputs) ? [] : $inputs;
            $currentSteps = $store->findCurrentSteps($id);

            $this->populateTransientMap($entry, $transientVars, $wf->getRegisters(), 0, $currentSteps, $ps);

            $globalActions = $wf->getGlobalActions();

            foreach ($globalActions as $action) {
                $transientVars['actionId'] = $action->getId();

                if ($action->getAutoExecute() && $this->isActionAvailable($action, $transientVars, $ps, 0)) {
                    $l[] = $action->getId();
                }
            }

            foreach ($currentSteps as $step) {
                $availableAutoActionsForStep = $this->getAvailableAutoActionsForStep($wf, $step, $transientVars, $ps);
                foreach ($availableAutoActionsForStep as $v) {
                    $l[] = $v;
                }
                //$l = array_merge($l, $availableAutoActionsForStep);
            }

            $l = array_unique($l);

            return $l;
        } catch (\Exception $e) {
            $errMsg = 'Ошибка при проверке доступных действий';
            $this->getLog()->error($errMsg, [$e]);
        }

        return [];
    }


    /**
     * @param WorkflowDescriptor   $wf
     * @param StepInterface        $step
     * @param array                $transientVars
     * @param PropertySetInterface $ps
     *
     * @return array
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    protected function getAvailableAutoActionsForStep(WorkflowDescriptor $wf, StepInterface $step, array &$transientVars, PropertySetInterface $ps)
    {
        $l = [];
        $s = $wf->getStep($step->getStepId());

        if (null === $s) {
            $msg = sprintf('getAvailableAutoActionsForStep вызвана с несуществующим id %s', $step->getStepId());
            $this->getLog()->debug($msg);
            return $l;
        }


        $actions = $s->getActions();
        if (null === $actions || 0 === $actions->count()) {
            return $l;
        }

        foreach ($actions as $action) {
            $transientVars['actionId'] = $action->getId();

            if ($action->getAutoExecute() && $this->isActionAvailable($action, $transientVars, $ps, 0)) {
                $l[] = $action->getId();
            }
        }

        return $l;
    }

    /**
     * @param ActionDescriptor $action
     * @param                  $id
     * @param array|Traversable $currentSteps
     * @param                  $state
     *
     * @return void
     *
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    protected function completeEntry(ActionDescriptor $action = null, $id, $currentSteps, $state)
    {
        if (!($currentSteps instanceof Traversable || is_array($currentSteps))) {
            $errMsg = 'CurrentSteps должен быть массивом, либо реализовывать интерфейс Traversable';
            throw new InvalidArgumentException($errMsg);
        }


        $this->getPersistence()->setEntryState($id, $state);

        $oldStatus = null !== $action ? $action->getUnconditionalResult()->getOldStatus() : 'Finished';
        $actionIdValue = null !== $action ? $action->getId() : -1;
        foreach ($currentSteps as $step) {
            $this->getPersistence()->markFinished($step, $actionIdValue, new DateTime(), $oldStatus, $this->context->getCaller());
            $this->getPersistence()->moveToHistory($step);
        }
    }
    /**
     * @param ResultDescriptor       $theResult
     * @param WorkflowEntryInterface $entry
     * @param WorkflowStoreInterface $store
     * @param integer                $actionId
     * @param StepInterface          $currentStep
     * @param array                  $previousIds
     * @param array                  $transientVars
     * @param PropertySetInterface   $ps
     *
     * @return StepInterface
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     */
    protected function createNewCurrentStep(
        ResultDescriptor $theResult,
        WorkflowEntryInterface $entry,
        WorkflowStoreInterface $store,
        $actionId,
        StepInterface $currentStep = null,
        array $previousIds = [],
        array &$transientVars,
        PropertySetInterface $ps
    ) {
        try {
            $nextStep = $theResult->getStep();

            if (-1 === $nextStep) {
                if (null !== $currentStep) {
                    $nextStep = $currentStep->getStepId();
                } else {
                    $errMsg = 'Неверный аргумент. Новый шаг является таким же как текущий. Но текущий шаг не указан';
                    throw new StoreException($errMsg);
                }
            }

            $owner = $theResult->getOwner();

            $logMsg = sprintf(
                'Результат: stepId=%s, status=%s, owner=%s, actionId=%s, currentStep=%s',
                $nextStep,
                $theResult->getStatus(),
                $owner,
                $actionId,
                null !== $currentStep ? $currentStep->getId() : 0
            );
            $this->getLog()->debug($logMsg);

            $variableResolver = $this->getConfiguration()->getVariableResolver();

            if (null !== $owner) {
                $o = $variableResolver->translateVariables($owner, $transientVars, $ps);
                $owner = null !== $o ? (string)$o : null;
            }


            $oldStatus = $theResult->getOldStatus();
            $oldStatus = (string)$variableResolver->translateVariables($oldStatus, $transientVars, $ps);

            $status = $theResult->getStatus();
            $status = (string)$variableResolver->translateVariables($status, $transientVars, $ps);


            if (null !== $currentStep) {
                $store->markFinished($currentStep, $actionId, new DateTime(), $oldStatus, $this->context->getCaller());
                $store->moveToHistory($currentStep);
            }

            $startDate = new DateTime();
            $dueDate = null;

            $theResultDueDate = (string)$theResult->getDueDate();
            $theResultDueDate = trim($theResultDueDate);
            if (strlen($theResultDueDate) > 0) {
                $dueDateObject = $variableResolver->translateVariables($theResultDueDate, $transientVars, $ps);

                if ($dueDateObject instanceof DateTime) {
                    $dueDate = $dueDateObject;
                } elseif (is_string($dueDateObject)) {
                    $dueDate = new DateTime($dueDate);
                } elseif (is_numeric($dueDateObject)) {
                    $dueDate = DateTime::createFromFormat('U', $dueDateObject);
                } else {
                    $errMsg = 'Ошибка при преобразование DueData';
                    throw new InternalWorkflowException($errMsg);
                }
            }

            $newStep = $store->createCurrentStep($entry->getId(), $nextStep, $owner, $startDate, $dueDate, $status, $previousIds);
            $transientVars['createdStep'] =  $newStep;

            if (null === $currentStep && 0 === count($previousIds)) {
                $currentSteps = [];
                $currentSteps[] = $newStep;
                $transientVars['currentSteps'] =  $currentSteps;
            }

            if (!array_key_exists('descriptor', $transientVars)) {
                $errMsg = 'Ошибка при получение дескриптора workflow из transientVars';
                throw new InternalWorkflowException($errMsg);
            }

            /** @var WorkflowDescriptor $descriptor */
            $descriptor = $transientVars['descriptor'];
            $step = $descriptor->getStep($nextStep);

            if (null === $step) {
                $errMsg = sprintf('Шаг #%s не найден', $nextStep);
                throw new WorkflowException($errMsg);
            }

            $preFunctions = $step->getPreFunctions();

            foreach ($preFunctions as $function) {
                $this->executeFunction($function, $transientVars, $ps);
            }
        } catch (WorkflowException $e) {
            $this->context->setRollbackOnly();
            throw $e;
        }
    }

    /**
     *
     *
     * Perform an action on the specified workflow instance.
     * @param integer $id The workflow instance id.
     * @param integer $actionId The action id to perform (action id's are listed in the workflow descriptor).
     * @param array $inputs The inputs to the workflow instance.
     * @throws \OldTown\Workflow\Exception\InvalidInputException if a validator is specified and an input is invalid.
     * @throws WorkflowException if the action is invalid for the specified workflow
     * instance's current state.
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\FactoryException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     * @throws \OldTown\Workflow\Exception\InvalidActionException
     * @throws \OldTown\Workflow\Exception\InvalidEntryStateException
     */
    public function doAction($id, $actionId, array $inputs = [])
    {
        $store = $this->getPersistence();
        $entry = $store->findEntry($id);

        if (WorkflowEntryInterface::ACTIVATED !== $entry->getState()) {
            return;
        }

        $wf = $this->getConfiguration()->getWorkflow($entry->getWorkflowName());

        $currentSteps = $store->findCurrentSteps($id);
        $action = null;

        $ps = $store->getPropertySet($id);
        $transientVars = [];

        $transientVars = array_merge($transientVars, $inputs);

        $this->populateTransientMap($entry, $transientVars, $wf->getRegisters(), $actionId, $currentSteps, $ps);


        $validAction = false;

        foreach ($wf->getGlobalActions() as $actionDesc) {
            if ($actionId === $actionDesc->getId()) {
                $action = $actionDesc;

                if ($this->isActionAvailable($action, $transientVars, $ps, 0)) {
                    $validAction = true;
                }
            }
        }


        foreach ($currentSteps as $step) {
            $s = $wf->getStep($step->getId());

            foreach ($s->getActions() as $actionDesc) {
                if ($actionId === $actionDesc->getId()) {
                    $action = $actionDesc;

                    if ($this->isActionAvailable($action, $transientVars, $ps, 0)) {
                        $validAction = true;
                    }
                }
            }
        }


        if (!$validAction) {
            $errMsg = sprintf(
                'Действие %s не прошло проверку',
                $actionId
            );
            throw new InvalidActionException($errMsg);
        }


        try {
            if ($this->transitionWorkflow($entry, $currentSteps, $store, $wf, $action, $transientVars, $inputs, $ps)) {
                $this->checkImplicitFinish($action, $id);
            }
        } catch (WorkflowException $e) {
            $this->context->setRollbackOnly();
            throw $e;
        }
    }

    /**
     * @param ActionDescriptor $action
     * @param                  $id
     *
     * @return void
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     */
    protected function checkImplicitFinish(ActionDescriptor $action, $id)
    {
        $store = $this->getPersistence();
        $entry = $store->findEntry($id);

        $wf = $this->getConfiguration()->getWorkflow($entry->getWorkflowName());

        $currentSteps = $store->findCurrentSteps($id);

        $isCompleted = $wf->getGlobalActions()->count() === 0;

        foreach ($currentSteps as $step) {
            if ($isCompleted) {
                break;
            }

            $stepDes = $wf->getStep($step->getStepId());

            if ($stepDes->getActions()->count() > 0) {
                $isCompleted = true;
            }
        }

        if ($isCompleted) {
            $this->completeEntry($action, $id, $currentSteps, WorkflowEntryInterface::COMPLETED);
        }
    }

    /**
     *
     * Check if the state of the specified workflow instance can be changed to the new specified one.
     * @param integer $id The workflow instance id.
     * @param integer $newState The new state id.
     * @return boolean true if the state of the workflow can be modified, false otherwise.
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     *
     */
    public function canModifyEntryState($id, $newState)
    {
        $store = $this->getPersistence();
        $entry = $store->findEntry($id);

        $currentState = $entry->getState();

        $result = false;
        try {
            switch ($newState) {
                case WorkflowEntryInterface::COMPLETED: {
                    if (WorkflowEntryInterface::ACTIVATED === $currentState) {
                        $result = true;
                    }
                    break;
                }

                //@TODO Разобраться с бизнес логикой. Может быть нужно добавить break
                /** @noinspection PhpMissingBreakStatementInspection */
                case WorkflowEntryInterface::CREATED: {
                    $result = false;
                }
                case WorkflowEntryInterface::ACTIVATED: {
                    if (WorkflowEntryInterface::CREATED === $currentState || WorkflowEntryInterface::SUSPENDED === $currentState) {
                        $result = true;
                    }
                    break;
                }
                case WorkflowEntryInterface::SUSPENDED: {
                    if (WorkflowEntryInterface::ACTIVATED === $currentState) {
                        $result = true;
                    }
                    break;
                }
                case WorkflowEntryInterface::KILLED: {
                    if (WorkflowEntryInterface::CREATED === $currentState || WorkflowEntryInterface::ACTIVATED === $currentState || WorkflowEntryInterface::SUSPENDED === $currentState) {
                        $result = true;
                    }
                    break;
                }
                default: {
                    $result = false;
                    break;
                }

            }

            return $result;
        } catch (StoreException $e) {
            $errMsg = sprintf(
                'Ошибка проверки изменения состояния для инстанса #%s',
                $id
            );
            $this->getLog()->error($errMsg, [$e]);
        }

        return false;
    }


    /**
     *
     * Возвращает коллекцию объектов описывающие состояние для текущего экземпляра workflow
     *
     * @param integer $id id экземпляра workflow
     * @return array
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function getCurrentSteps($id)
    {
        try {
            $store = $this->getPersistence();

            $result = $store->findCurrentSteps($id);

            return $result;
        } catch (StoreException $e) {
            $errMsg = sprintf(
                'Ошибка при проверке текущего шага для инстанса # %s',
                $id
            );
            $this->getLog()->error($errMsg, [$e]);


            return [];
        }
    }

    /**
     *
     *
     * Modify the state of the specified workflow instance.
     * @param integer $id The workflow instance id.
     * @param integer $newState the new state to change the workflow instance to.
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InvalidEntryStateException
     */
    public function changeEntryState($id, $newState)
    {
        $store = $this->getPersistence();
        $entry = $store->findEntry($id);

        if ($newState === $entry->getState()) {
            return;
        }

        if ($this->canModifyEntryState($id, $newState)) {
            if (WorkflowEntryInterface::KILLED === $newState || WorkflowEntryInterface::COMPLETED === $newState) {
                $currentSteps = $this->getCurrentSteps($id);

                if (count($currentSteps) > 0) {
                    $this->completeEntry(null, $id, $currentSteps, $newState);
                }
            }

            $store->setEntryState($id, $newState);
        } else {
            $errMsg = sprintf(
                'Не возможен переход в экземпляре workflow #%s. Текущее состояние %s, ожидаемое состояние %s',
                $id,
                $entry->getState(),
                $newState
            );

            throw new InvalidEntryStateException($errMsg);
        }

        $msg = sprintf(
            '%s : Новое состояние: %s',
            $entry->getId(),
            $entry->getState()
        );
        $this->getLog()->debug($msg);
    }


    /**
     * @param FunctionDescriptor $function
     * @param array $transientVars
     * @param PropertySetInterface $ps
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     */
    protected function executeFunction(FunctionDescriptor $function, array &$transientVars, PropertySetInterface $ps)
    {
        if (null !== $function) {
            $type = $function->getType();

            $argsOriginal = $function->getArgs();
            $args = [];

            foreach ($argsOriginal as $k => $v) {
                $translateValue = $this->getConfiguration()->getVariableResolver()->translateVariables($v, $transientVars, $ps);
                $args[$k] = $translateValue;
            }

            $provider = $this->getResolver()->getFunction($type, $args);

            if (null === $provider) {
                $this->context->setRollbackOnly();
                $errMsg = 'Не загружен провайдер для функции';
                throw new WorkflowException($errMsg);
            }

            try {
                $provider->execute($transientVars, $args, $ps);
            } catch (WorkflowException $e) {
                $this->context->setRollbackOnly();
                throw $e;
            }
        }
    }


    /**
     * @param WorkflowEntryInterface $entry
     * @param $validatorsStorage
     * @param array $transientVars
     * @param PropertySetInterface $ps
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    protected function verifyInputs(WorkflowEntryInterface $entry, $validatorsStorage, array  &$transientVars, PropertySetInterface $ps)
    {
        if ($validatorsStorage instanceof Traversable) {
            $validators = [];
            foreach ($validatorsStorage as $k => $v) {
                $validators[$k] = $v;
            }
        } elseif (is_array($validatorsStorage)) {
            $validators = $validatorsStorage;
        } else {
            $errMsg = sprintf(
                'Validators должен быть массивом, либо реализовывать интерфейс Traversable. EntryId: %s',
                $entry->getId()
            );
            throw new InvalidArgumentException($errMsg);
        }

        /** @var ValidatorDescriptor[] $validators */
        foreach ($validators as $input) {
            if (null !== $input) {
                $type = $input->getType();
                $argsOriginal = $input->getArgs();

                $args = [];

                foreach ($argsOriginal as $k => $v) {
                    $translateValue = $this->getConfiguration()->getVariableResolver()->translateVariables($v, $transientVars, $ps);
                    $args[$k] = $translateValue;
                }


                $validator = $this->getResolver()->getValidator($type, $args);

                if (null === $validator) {
                    $this->context->setRollbackOnly();
                    $errMsg = 'Ошибка при загрузке валидатора';
                    throw new WorkflowException($errMsg);
                }

                try {
                    $validator->validate($transientVars, $args, $ps);
                } catch (InvalidInputException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    $this->context->setRollbackOnly();

                    if ($e instanceof WorkflowException) {
                        throw $e;
                    }

                    $errMsg = 'Неизвестная ошибка при работе валидатора';
                    throw new WorkflowException($errMsg, $e->getCode(), $e);
                }
            }
        }
    }


    /**
     * Возвращает текущий шаг
     *
     * @param WorkflowDescriptor $wfDesc
     * @param integer $actionId
     * @param StepInterface[] $currentSteps
     * @param array $transientVars
     * @param PropertySetInterface $ps
     *
     * @return StepInterface
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    protected function getCurrentStep(WorkflowDescriptor $wfDesc, $actionId, array $currentSteps = [], array &$transientVars, PropertySetInterface $ps)
    {
        if (1 === count($currentSteps)) {
            reset($currentSteps);
            return current($currentSteps);
        }


        foreach ($currentSteps as $step) {
            $stepId = $step->getId();
            $action = $wfDesc->getStep($stepId)->getAction($actionId);

            if ($this->isActionAvailable($action, $transientVars, $ps, $stepId)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param ActionDescriptor|null $action
     * @param $transientVars
     * @param PropertySetInterface $ps
     * @param $stepId
     *
     * @return boolean
     *
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     */
    protected function isActionAvailable(ActionDescriptor $action = null, &$transientVars, PropertySetInterface $ps, $stepId)
    {
        if (null === $action) {
            return false;
        }

        $result = null;
        $actionHash = spl_object_hash($action);
        if (null !== $this->stateCache) {
            $result = array_key_exists($actionHash, $this->stateCache) ? $this->stateCache[$actionHash] : $result;
        } else {
            $this->stateCache = [];
        }

        $wf = $this->getWorkflowDescriptorForAction($action);


        if (null === $result) {
            $restriction = $action->getRestriction();
            $conditions = null;

            if (null !== $restriction) {
                $conditions = $restriction->getConditionsDescriptor();
            }

            $result = $this->passesConditionsByDescriptor($wf->getGlobalConditions(), $transientVars, $ps, $stepId)
                && $this->passesConditionsByDescriptor($conditions, $transientVars, $ps, $stepId);

            $this->stateCache[$actionHash] = $result;
        }


        $result = (boolean)$result;

        return $result;
    }

    /**
     * По дейсвтию получаем дексрипторв workflow
     *
     * @param ActionDescriptor $action
     * @return WorkflowDescriptor
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    private function getWorkflowDescriptorForAction(ActionDescriptor $action)
    {
        $objWfd = $action;

        $count = 0;
        while (!$objWfd instanceof WorkflowDescriptor || null === $objWfd) {
            $objWfd = $objWfd->getParent();

            $count++;
            if ($count > 10) {
                $errMsg = 'Ошибка при получение WorkflowDescriptor';
                throw new InternalWorkflowException($errMsg);
            }
        }

        $wf = $objWfd;

        return $wf;
    }

    /**
     * Инициализация workflow. Workflow нужно иницаилизровать прежде, чем выполнять какие либо действия.
     * Workflow может быть инициализированно только один раз
     *
     * @param string $workflowName Имя workflow
     * @param integer $initialAction Имя первого шага, с которого начинается workflow
     * @param array $inputs Данные введеные пользователем
     * @return integer
     * @throws \OldTown\Workflow\Exception\InvalidRoleException
     * @throws \OldTown\Workflow\Exception\InvalidInputException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\InvalidEntryStateException
     * @throws \OldTown\Workflow\Exception\InvalidActionException
     * @throws \OldTown\Workflow\Exception\FactoryException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     *
     */
    public function initialize($workflowName, $initialAction, array $inputs = null)
    {
        $initialAction = (integer)$initialAction;

        $wf = $this->getConfiguration()->getWorkflow($workflowName);

        $store = $this->getPersistence();

        $entry = $store->createEntry($workflowName);

        $ps = $store->getPropertySet($entry->getId());


        $transientVars = [];

        if (null !== $inputs) {
            $transientVars = $inputs;
        }

        $this->populateTransientMap($entry, $transientVars, $wf->getRegisters(), $initialAction, [], $ps);

        if (!$this->canInitializeInternal($workflowName, $initialAction, $transientVars, $ps)) {
            $this->context->setRollbackOnly();
            $errMsg = 'Вы не можете инициироват данный рабочий процесс';
            throw new InvalidRoleException($errMsg);
        }

        $action = $wf->getInitialAction($initialAction);

        try {
            $this->transitionWorkflow($entry, [], $store, $wf, $action, $transientVars, $inputs, $ps);
        } catch (WorkflowException $e) {
            $this->context->setRollbackOnly();
            throw $e;
        }

        $entryId = $entry->getId();

        // now clone the memory PS to the real PS
        //PropertySetManager.clone(ps, store.getPropertySet(entryId));
        return $entryId;
    }

    /**
     * Проверяет имеет ли пользователь достаточно прав, что бы иниициировать вызываемый процесс
     *
     * @param string $workflowName имя workflow
     * @param integer $initialAction id начального состояния
     * @param array|null $inputs
     *
     * @return bool
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\FactoryException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     */
    public function canInitialize($workflowName, $initialAction, array $inputs = null)
    {
        $mockWorkflowName = $workflowName;
        $mockEntry = new SimpleWorkflowEntry(0, $mockWorkflowName, WorkflowEntryInterface::CREATED);

        try {
            $ps = PropertySetManager::getInstance('memory', null);
        } catch (\Exception $e) {
            $errMsg = sprintf('Ошибка при создание PropertySer: %s', $e->getMessage());
            throw new InternalWorkflowException($errMsg);
        }

        $transientVars = [];

        if (null !== $inputs) {
            $transientVars = array_merge($transientVars, $inputs);
        }

        try {
            $this->populateTransientMap($mockEntry, $transientVars, [], $initialAction, [], $ps);

            $result = $this->canInitializeInternal($workflowName, $initialAction, $transientVars, $ps);

            return $result;
        } catch (InvalidActionException $e) {
            $this->getLog()->error($e->getMessage(), [$e]);

            return false;
        } catch (WorkflowException $e) {
            $errMsg = sprintf(
                'Ошибка при проверки canInitialize: %s',
                $e->getMessage()
            );
            $this->getLog()->error($errMsg, [$e]);

            return false;
        }
    }


    /**
     * Проверяет имеет ли пользователь достаточно прав, что бы иниициировать вызываемый процесс
     *
     * @param string $workflowName имя workflow
     * @param integer $initialAction id начального состояния
     * @param array|null $transientVars
     *
     * @param PropertySetInterface $ps
     *
     * @return bool
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     * @throws \OldTown\Workflow\Exception\InvalidActionException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     */
    protected function canInitializeInternal($workflowName, $initialAction, array &$transientVars, PropertySetInterface $ps)
    {
        $wf = $this->getConfiguration()->getWorkflow($workflowName);

        $actionDescriptor = $wf->getInitialAction($initialAction);

        if (null === $actionDescriptor) {
            $errMsg = sprintf(
                'Некорректное инициирующие действие # %s',
                $initialAction
            );
            throw new InvalidActionException($errMsg);
        }

        $restriction = $actionDescriptor->getRestriction();


        $conditions = null;
        if (null !== $restriction) {
            $conditions = $restriction->getConditionsDescriptor();
        }

        $passesConditions = $this->passesConditionsByDescriptor($conditions, $transientVars, $ps, 0);

        return $passesConditions;
    }

    /**
     * @param ConditionsDescriptor $descriptor
     * @param array $transientVars
     * @param PropertySetInterface $ps
     * @param                      $currentStepId
     *
     * @return bool
     *
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     */
    protected function passesConditionsByDescriptor(ConditionsDescriptor $descriptor = null, array &$transientVars, PropertySetInterface $ps, $currentStepId)
    {
        if (null === $descriptor) {
            return true;
        }

        $type = $descriptor->getType();
        $conditions = $descriptor->getConditions();
        $passesConditions = $this->passesConditions($type, $conditions, $transientVars, $ps, $currentStepId);

        return $passesConditions;
    }

    /**
     * Этим полукостылём я портировал перегрузку методов в java
     *
     * @return bool
     *
     * @throws Exception\InvalidActionException
     */
    protected function passesConditions() {
        $arguments = func_get_args();

        if (count($arguments) === 4) {
            /** @var ConditionsDescriptor $descriptor */
            $descriptor = $arguments[0];
            if ($descriptor == null) {
                return true;
            }

            return $this->passesConditionsWithType(
                $descriptor->getType(),
                $descriptor->getConditions(),
                $arguments[1],
                $arguments[2],
                $arguments[3]
            );
        } elseif (count($arguments) === 5) {
            return $this->passesConditionsWithType(
                $arguments[0],
                $arguments[1],
                $arguments[2],
                $arguments[3],
                $arguments[4]
            );
        }

        throw new Exception\InvalidActionException('Incorrect params!!');
    }

    /**
     * @param string $conditionType
     * @param array $conditionsStorage
     * @param array $transientVars
     * @param PropertySetInterface $ps
     * @param integer $currentStepId
     *
     * @return bool
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     */
    protected function passesConditionsWithType($conditionType, $conditionsStorage = null, array &$transientVars, PropertySetInterface $ps, $currentStepId)
    {
        if (null === $conditionsStorage) {
            return true;
        }


        if ($conditionsStorage instanceof Traversable) {
            $conditions = [];
            foreach ($conditionsStorage as $k => $v) {
                $conditions[$k] = $v;
            }
        } elseif (is_array($conditionsStorage)) {
            $conditions = $conditionsStorage;
        } else {
            $errMsg = 'Conditions должен быть массивом, либо реализовывать интерфейс Traversable';
            throw new InvalidArgumentException($errMsg);
        }


        if (0 === count($conditions)) {
            return true;
        }


        $and = strtoupper($conditionType) === 'AND';
        $or = !$and;

        foreach ($conditions as $descriptor) {
            if ($descriptor instanceof ConditionsDescriptor) {
                $result = $this->passesConditions($descriptor->getType(), $descriptor->getConditions(), $transientVars, $ps, $currentStepId);
            } else {
                $result = $this->passesCondition($descriptor, $transientVars, $ps, $currentStepId);
            }

            if ($and && !$result) {
                return false;
            } elseif ($or && $result) {
                return true;
            }
        }

        if ($and) {
            return true;
        } elseif ($or) {
            return false;
        } else {
            return false;
        }
    }

    /**
     * @param ConditionDescriptor $conditionDesc
     * @param array $transientVars
     * @param PropertySetInterface $ps
     * @param integer $currentStepId
     *
     * @return boolean
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     */
    protected function passesCondition(ConditionDescriptor $conditionDesc, array &$transientVars, PropertySetInterface $ps, $currentStepId)
    {
        $type = $conditionDesc->getType();

        $argsOriginal = $conditionDesc->getArgs();


        $args = [];
        foreach ($argsOriginal as $key => $value) {
            $translateValue = $this->getConfiguration()->getVariableResolver()->translateVariables($value, $transientVars, $ps);
            $args[$key] = $translateValue;
        }

        if (-1 !== $currentStepId) {
            $stepId = array_key_exists('stepId', $args) ? (integer)$args['stepId'] : null;

            if (null !== $stepId && -1 === $stepId) {
                $args['stepId'] = $currentStepId;
            }
        }

        $condition = $this->getResolver()->getCondition($type, $args);

        if (null === $condition) {
            $this->context->setRollbackOnly();
            $errMsg = 'Огибка при загрузки условия';
            throw new WorkflowException($errMsg);
        }

        try {
            $passed = $condition->passesCondition($transientVars, $args, $ps);

            if ($conditionDesc->isNegate()) {
                $passed = !$passed;
            }
        } catch (\Exception $e) {
            $this->context->setRollbackOnly();

            $errMsg = sprintf(
                'Ошбика при выполнение условия %s',
                get_class($condition)
            );

            throw new WorkflowException($errMsg, $e->getCode(), $e);
        }

        return $passed;
    }

    /**
     * @param WorkflowEntryInterface $entry
     * @param array $transientVars
     * @param array|Traversable|RegisterDescriptor[]|SplObjectStorage $registersStorage
     * @param integer $actionId
     * @param array $currentSteps
     * @param PropertySetInterface $ps
     *
     *
     * @return $this
     *
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\FactoryException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     */
    protected function populateTransientMap(WorkflowEntryInterface $entry, array &$transientVars, $registersStorage, $actionId = null, array $currentSteps, PropertySetInterface $ps)
    {
        if ($registersStorage instanceof Traversable) {
            $registers = [];
            foreach ($registersStorage as $k => $v) {
                $registers[$k] = $v;
            }
        } elseif (is_array($registersStorage)) {
            $registers = $registersStorage;
        } else {
            $errMsg = 'Registers должен быть массивом, либо реализовывать интерфейс Traversable';
            throw new InvalidArgumentException($errMsg);
        }
        /** @var RegisterDescriptor[] $registers */

        $transientVars['context'] = $this->context;
        $transientVars['entry'] = $entry;
        $transientVars['store'] = $this->getPersistence();
        $transientVars['configuration'] = $this->getConfiguration();
        $transientVars['descriptor'] = $this->getConfiguration()->getWorkflow($entry->getWorkflowName());

        if (null !== $actionId) {
            $actionId = (integer)$actionId;
            $transientVars['actionId'] = $actionId;
        }

        $transientVars['currentSteps'] = $currentSteps;


        foreach ($registers as $register) {
            $args = $register->getArgs();
            $type = $register->getType();


            $r = $this->getResolver()->getRegister($type, $args);

            if (null === $r) {
                $errMsg = 'Ошибка при инициализации register';
                $this->context->setRollbackOnly();
                throw new WorkflowException($errMsg);
            }

            try {
                $variableName = $register->getVariableName();
                $value = $r->registerVariable($this->context, $entry, $args, $ps);

                $transientVars[$variableName] = $value;
            } catch (\Exception $e) {
                $this->context->setRollbackOnly();

                $errMsg = sprintf(
                    'Ошибка при регистрации переменной в регистре %s',
                    get_class($r)
                );

                throw new WorkflowException($errMsg, $e->getCode(), $e);
            }
        }
    }

    /**
     * Возвращает резолвер
     *
     * @return TypeResolver
     */
    public function getResolver()
    {
        if (null !== $this->typeResolver) {
            return $this->typeResolver;
        }

        $this->typeResolver = TypeResolver::getResolver();

        return $this->typeResolver;
    }

    /**
     * Возвращает хранилище состояния workflow
     *
     * @return WorkflowStoreInterface
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    protected function getPersistence()
    {
        return $this->getConfiguration()->getWorkflowStore();
    }

    /**
     * Получить конфигурацию workflow. Метод также проверяет была ли иницилазированн конфигурация, если нет, то
     * инициализирует ее.
     *
     * Если конфигурация не была установленна, то возвращает конфигурацию по умолчанию
     *
     * @return ConfigurationInterface|DefaultConfiguration Конфигурация которая была установленна
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function getConfiguration()
    {
        $config = null !== $this->configuration ? $this->configuration : DefaultConfiguration::getInstance();

        if (!$config->isInitialized()) {
            try {
                $config->load(null);
            } catch (FactoryException $e) {
                $errMsg = 'Ошибка при иницилазации конфигурации workflow';
                $this->getLog()->critical($errMsg, ['exception' => $e]);
                throw new InternalWorkflowException($errMsg, $e->getCode(), $e);
            }
        }

        return $config;
    }

    /**
     * @return LoggerInterface
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * @param LoggerInterface $log
     *
     * @return $this
     * @throws InternalWorkflowException
     */
    public function setLog($log)
    {
        try {
            LogFactory::validLogger($log);
        } catch (\Exception $e) {
            $errMsg = 'Ошибка при валидации логера';
            throw new InternalWorkflowException($errMsg, $e->getCode(), $e);
        }


        $this->log = $log;

        return $this;
    }


    /**
     * Get the workflow descriptor for the specified workflow name.
     *
     * @param string $workflowName The workflow name.
     * @return WorkflowDescriptor
     * @throws InternalWorkflowException
     */
    public function getWorkflowDescriptor($workflowName)
    {
        try {
            $w = $this->getConfiguration()->getWorkflow($workflowName);
            return $w;
        } catch (FactoryException $e) {
            $errMsg = 'Ошибка при загрузке workflow';
            $this->getLog()->error($errMsg, ['exception' => $e]);
            throw new InternalWorkflowException($errMsg, $e->getCode(), $e);
        }
    }


    /**
     * Executes a special trigger-function using the context of the given workflow instance id.
     * Note that this method is exposed for Quartz trigger jobs, user code should never call it.
     * @param integer $id The workflow instance id
     * @param integer $triggerId The id of the special trigger-function
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\FactoryException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     */
    public function executeTriggerFunction($id, $triggerId)
    {
        $store = $this->getPersistence();
        $entry = $store->findEntry($id);

        if (null === $entry) {
            $errMsg = sprintf(
                'Ошибка при выполнение тригера # %s для несуществующего экземпляра workflow id# %s',
                $triggerId,
                $id
            );
            $this->getLog()->warning($errMsg);
            return;
        }

        $wf = $this->getConfiguration()->getWorkflow($entry->getWorkflowName());

        $ps = $store->getPropertySet($id);
        $transientVars = [];

        $this->populateTransientMap($entry, $transientVars, $wf->getRegisters(), null, $store->findCurrentSteps($id), $ps);

        $this->executeFunction($wf->getTriggerFunction($triggerId), $transientVars, $ps);
    }

    /**
     * @param $id
     * @param $inputs
     *
     * @return array
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     * @throws \OldTown\Workflow\Exception\InvalidArgumentException
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\FactoryException
     */
    public function getAvailableActions($id, $inputs)
    {
        try {
            $store = $this->getPersistence();
            $entry = $store->findEntry($id);

            if (null === $entry) {
                $errMsg = sprintf(
                    'Не существует экземпляра workflow c id %s',
                    $id
                );
                throw new InvalidArgumentException($errMsg);
            }

            if (WorkflowEntryInterface::ACTIVATED === $entry->getState()) {
                return [];
            }

            $wf = $this->getConfiguration()->getWorkflow($entry->getWorkflowName());

            if (null === $wf) {
                $errMsg = sprintf(
                    'Не существует workflow c именем %s',
                    $entry->getWorkflowName()
                );
                throw new InvalidArgumentException($errMsg);
            }

            $l = [];
            $ps = $store->getPropertySet($id);

            $transientVars = is_array($inputs) || $inputs instanceof Traversable  ? $inputs : [];
            $currentSteps = $store->findCurrentSteps($id);

            $this->populateTransientMap($entry, $transientVars, $wf->getRegisters(), 0, $currentSteps, $ps);

            $globalActions = $wf->getGlobalActions();

            foreach ($globalActions as $action) {
                $restriction = $action->getRestriction();
                $conditions = null;

                $transientVars['actionId'] = $action->getId();

                if (null !== $restriction) {
                    $conditions = $restriction->getConditionsDescriptor();
                }

                $flag = $this->passesConditions($wf->getGlobalActions(), $transientVars, $ps, 0) && $this->passesConditions($conditions, $transientVars, $ps, 0);
                if ($flag) {
                    $l[] = $action->getId();
                }
            }


            foreach ($currentSteps as $step) {
                $data = $this->getAvailableActionsForStep($wf, $step, $transientVars, $ps);
                foreach ($data as $v) {
                    $l[] = $v;
                }
            }
            $actions = array_unique($l);

            return $actions;
        } catch (\Exception $e) {
            $errMsg = 'Ошибка проверки доступных действий';
            $this->getLog()->error($errMsg, [$e]);
        }

        return [];
    }

    /**
     * @param WorkflowDescriptor   $wf
     * @param StepInterface        $step
     * @param array                $transientVars
     * @param PropertySetInterface $ps
     *
     * @return array
     * @throws \OldTown\Workflow\Exception\ArgumentNotNumericException
     */
    protected function getAvailableActionsForStep(WorkflowDescriptor $wf, StepInterface $step, array &$transientVars, PropertySetInterface $ps)
    {
        $l = [];
        $s = $wf->getStep($step->getStepId());

        if (null === $s) {
            $errMsg = sprintf(
                'getAvailableActionsForStep вызван с не существующим id шага %s',
                $step->getStepId()
            );

            $this->getLog()->warning($errMsg);

            return $l;
        }

        $actions  = $s->getActions();

        if (null === $actions || 0  === $actions->count()) {
            return $l;
        }

        foreach ($actions as $action) {
            $restriction = $action->getRestriction();
            $conditions = null;

            $transientVars['actionId'] = $action->getId();


            if (null !== $restriction) {
                $conditions = $restriction->getConditionsDescriptor();
            }

            $f = $this->passesConditions($wf->getGlobalConditions(), $transientVars, $ps, $s->getId())
                 && $this->passesConditions($conditions, $transientVars, $ps, $s->getId());
            if ($f) {
                $l[] = $action->getId();
            }
        }

        return $l;
    }

    /**
     * @param ConfigurationInterface $configuration
     *
     * @return $this
     */
    public function setConfiguration(ConfigurationInterface $configuration)
    {
        $this->configuration = $configuration;

        return $this;
    }

    /**
     * Возвращает состояние для текущего экземпляра workflow
     *
     * @param integer $id id экземпляра workflow
     * @return integer id текущего состояния
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function getEntryState($id)
    {
        try {
            $store = $this->getPersistence();

            $result = $store->findEntry($id)->getState();

            return $result;
        } catch (StoreException $e) {
            $errMsg = sprintf(
                'Ошибка при получение состояния экземпляра workflow c id# %s',
                $id
            );
            $this->getLog()->error($errMsg, [$e]);
        }

        return WorkflowEntryInterface::UNKNOWN;
    }

    /**
     * Returns a list of all steps that are completed for the given workflow instance id.
     *
     * @param integer $id The workflow instance id.
     * @return StepInterface[] a List of Steps
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function getHistorySteps($id)
    {
        try {
            $store = $this->getPersistence();

            $result = $store->findHistorySteps($id);

            return $result;
        } catch (StoreException $e) {
            $errMsg = sprintf(
                'Ошибка при получение истории шагов для экземпляра workflow c id# %s',
                $id
            );
            $this->getLog()->error($errMsg, [$e]);
        }

        return [];
    }

    /**
     * Настройки хранилища
     *
     * @return array
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function getPersistenceProperties()
    {
        $p = $this->getConfiguration()->getPersistenceArgs();

        return $p;
    }


    /**
     * Get the PropertySet for the specified workflow instance id.
     * @param integer $id The workflow instance id.
     * @return PropertySetInterface
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function getPropertySet($id)
    {
        $ps = null;

        try {
            $ps = $this->getPersistence()->getPropertySet($id);
        } catch (StoreException $e) {
            $errMsg = sprintf(
                'Ошибка при получение PropertySet для экземпляра workflow c id# %s',
                $id
            );
            $this->getLog()->error($errMsg, [$e]);
        }

        return $ps;
    }

    /**
     * @return \String[]
     */
    public function getWorkflowNames()
    {
        try {
            $result =  $this->getConfiguration()->getWorkflowNames();
            return $result;
        } catch (FactoryException $e) {
            $errMsg = 'Ошибка при получение имен workflow';
            $this->getLog()->error($errMsg, [$e]);
        }

        return [];
    }

    /**
     * @param TypeResolver $typeResolver
     *
     * @return $this
     */
    public function setTypeResolver(TypeResolver $typeResolver)
    {
        $this->typeResolver = $typeResolver;

        return $this;
    }


    /**
     * Get a collection (Strings) of currently defined permissions for the specified workflow instance.
     * @param integer $id id the workflow instance id.
     * @param array $inputs inputs The inputs to the workflow instance.
     * @return array  A List of permissions specified currently (a permission is a string name).
     *
     */
    public function getSecurityPermissions($id, array $inputs = [])
    {
        try {
            $store = $this->getPersistence();
            $entry = $store->findEntry($id);
            $wf = $this->getConfiguration()->getWorkflow($entry->getWorkflowName());

            $ps = $store->getPropertySet($id);

            $transientVars = is_array($inputs) || $inputs instanceof Traversable ? $inputs : [];
            $currentSteps = $store->findCurrentSteps($id);

            try {
                $this->populateTransientMap($entry, $transientVars, $wf->getRegisters(), null, $currentSteps, $ps);
            } catch (\Exception $e) {
                $errMsg = sprintf(
                    'Внутреннея ошибка: %s',
                    $e->getMessage()
                );
                throw new InternalWorkflowException($errMsg, $e->getCode(), $e);
            }


            $s = [];

            foreach ($currentSteps as $step) {
                $stepId = $step->getStepId();

                $xmlStep = $wf->getStep($stepId);

                $securities = $xmlStep->getPermissions();

                foreach ($securities as $security) {
                    $ConditionsDescriptor = $security->getRestriction()->getConditionsDescriptor();
                    if (null !== $security->getRestriction() && $this->passesConditions($ConditionsDescriptor, $transientVars, $ps, $xmlStep->getId())) {
                        $s[$security->getName()] = $security->getName();
                    }
                }
            }

            return $s;
        } catch (\Exception $e) {
            $errMsg = sprintf(
                'Ошибка при получение информации о правах доступа для экземпляра workflow c id# %s',
                $id
            );
            $this->getLog()->error($errMsg, [$e]);
        }

        return [];
    }


    /**
     * Get the name of the specified workflow instance.
     *
     * @param integer $id the workflow instance id.
     * @return string
     */
    public function getWorkflowName($id)
    {
        try {
            $store = $this->getPersistence();
            $entry = $store->findEntry($id);

            if (null !== $entry) {
                return $entry->getWorkflowName();
            }
        } catch (FactoryException $e) {
            $errMsg = sprintf(
                'Ошибка при получение имен workflow для инстанса с id # %s',
                $id
            );
            $this->getLog()->error($errMsg, [$e]);
        }

        return null;
    }

    /**
     * Удаляет workflow
     *
     * @param string $workflowName
     *
     * @return bool
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function removeWorkflowDescriptor($workflowName)
    {
        $result = $this->getConfiguration()->removeWorkflow($workflowName);

        return $result;
    }

    /**
     * @param                    $workflowName
     * @param WorkflowDescriptor $descriptor
     * @param                    $replace
     *
     * @return bool
     *
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException
     */
    public function saveWorkflowDescriptor($workflowName, WorkflowDescriptor $descriptor, $replace)
    {
        $success = $this->getConfiguration()->saveWorkflow($workflowName, $descriptor, $replace);

        return $success;
    }


    /**
     * Query the workflow store for matching instances
     *
     * @param WorkflowExpressionQuery $query
     *
     * @return array
     *
     * @throws \OldTown\Workflow\Exception\WorkflowException
     * @throws \OldTown\Workflow\Exception\StoreException
     * @throws \OldTown\Workflow\Exception\InternalWorkflowException

     */
    public function query(WorkflowExpressionQuery $query)
    {
        $result = $this->getPersistence()->query($query);

        return $result;
    }
}
