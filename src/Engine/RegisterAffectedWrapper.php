<?php
/**
 * Created by PhpStorm.
 * User: kota
 * Date: 21.06.16
 * Time: 21:09
 */

namespace OldTown\Workflow\Engine;

/**
 * обертка гарантирующая освобождение ресурса (удаление из стека идентификатора ентити)
 * Class RegisterAffectedWrapper
 * @package OldTown\Workflow\Engine
 */
class RegisterAffectedWrapper
{
    /** @var Transition  */
    private $transition;
    /** @var  integer */
    private $id;

    public function __construct(Transition $transition, $id, $action)
    {
        $this->transition = $transition;
        $this->id = $id;
        $this->transition->registerAffectedEntity($id, $action);
    }

    public function __destruct()
    {
        $this->transition->unregisterAffectedEntity($this->id);
    }
}
