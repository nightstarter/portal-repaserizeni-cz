<?php

namespace App\Console\Commands;

use App\Application\Messages\DiscussionNotificationDispatcher;
use Illuminate\Console\Command;

class DispatchDiscussionNotifications extends Command
{
    protected $signature = 'notifications:dispatch {--limit= : Maximum number of deliveries to claim}';

    protected $description = 'Dispatch due discussion and buyback e-mail notifications from the transactional outbox';

    public function handle(DiscussionNotificationDispatcher $dispatcher): int
    {
        $limit = $this->option('limit');
        $limit = $limit === null || $limit === ''
            ? (int) config('discussion_notifications.batch_size', 50)
            : filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('The --limit value must be an integer between 1 and 500.');

            return self::INVALID;
        }

        $result = $dispatcher->dispatch($limit);
        if ($result['disabled']) {
            $this->warn('Discussion e-mail delivery is disabled; outbox rows were left unchanged.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Claimed %d; sent %d; retry scheduled %d; permanently failed %d.',
            $result['claimed'],
            $result['sent'],
            $result['retried'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
