<?php
/**
 * @link https://github.com/old-town/old-town-workflow
 * @author  Malofeykin Andrey  <and-rey2@yandex.ru>
 */
namespace OldTown\Workflow\Exception;

/**
 * Class UnregisteredStackException
 * @package OldTown\Workflow\Exception
 */
class UnregisteredStackException extends RuntimeException
{
    private $stack = [];

    public function __construct($message='', $stack, $code=0, $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->stack = $stack;
    }

    public function getStack()
    {
        return $this->stack;
    }
}
