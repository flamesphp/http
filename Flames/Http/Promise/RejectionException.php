<?php

declare(strict_types=1);

namespace Flames\Http\Promise;

/**
 * A special exception that is thrown when waiting on a rejected promise.
 *
 * The reason value is available via the getReason() method.
 */
class RejectionException extends \RuntimeException
{
    /**
     * @param mixed       $reason      Rejection reason.
     * @param string|null $description Optional description.
     */
    public function __construct(private $reason, ?string $description = null)
    {
        $message = 'The promise was rejected';

        if ($description) {
            $message .= ' with reason: '.$description;
        } elseif (is_string($this->reason)
            || (is_object($this->reason) && method_exists($this->reason, '__toString'))
        ) {
            $message .= ' with reason: '.$this->reason;
        } elseif ($this->reason instanceof \JsonSerializable) {
            $message .= ' with reason: '.json_encode($this->reason, JSON_PRETTY_PRINT);
        }

        parent::__construct($message);
    }

    /**
     * Returns the rejection reason.
     *
     * @return mixed
     */
    public function getReason()
    {
        return $this->reason;
    }
}
