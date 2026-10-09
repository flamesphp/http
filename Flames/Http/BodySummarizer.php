<?php
declare(strict_types=1);


// HttpGuzzle fork: https://github.com/guzzle/guzzle

namespace Flames\Http;

use Flames\Http\Psr\Http\Message\MessageInterface;

final readonly class BodySummarizer implements BodySummarizerInterface
{
    public function __construct(private ?int $truncateAt = null)
    {
    }

    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string
    {
        return $this->truncateAt === null
            ? Psr7\Message::bodySummary($message)
            : Psr7\Message::bodySummary($message, $this->truncateAt);
    }
}
