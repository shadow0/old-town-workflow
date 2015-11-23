<?php
/**
 * @link https://github.com/old-town/old-town-workflow
 * @author  Malofeykin Andrey  <and-rey2@yandex.ru>
 */
namespace OldTown\Workflow\TransientVars;

use Countable;
use IteratorAggregate;
use Serializable;
use ArrayAccess;
use Traversable;


/**
 * Interface TransientVarsInterface
 *
 * @package OldTown\Workflow\TransientVars
 */
interface TransientVarsInterface extends IteratorAggregate, Traversable, ArrayAccess, Serializable, Countable
{
}
