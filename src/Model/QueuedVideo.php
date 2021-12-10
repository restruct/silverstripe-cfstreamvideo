<?php

namespace Restruct\SilverStripe\StreamVideo\Model;

use SilverStripe\Core\ClassInfo;
use Symbiote\QueuedJobs\Jobs\ScheduledExecutionJob;
use Restruct\SilverStripe\StreamVideo\StreamApi\CloudflareStreamApiClient;

trait QueuedVideo
{
    // Facilitate uploading as QueuedJob
    public $ExecuteFree = 'never';

    private function scheduleUploadJob($write = true)
    {
        if ($this->StatusState || !ClassInfo::exists('Symbiote\QueuedJobs\Jobs\ScheduledExecutionJob')) {
            return false;
        }
        // using full namespaced classnames because qjobs module may not be installed (so we cannot use imports)
        $qJobDescrID = singleton(\Symbiote\QueuedJobs\Services\QueuedJobService::class)->queueJob(
            new \Symbiote\QueuedJobs\Jobs\ScheduledExecutionJob($this)
        );
        $this->StatusState = CloudflareStreamApiClient::STATUS_SCHEDULED;
        if ($write) {
            $this->write(); // triggers double write because called from onAfterWrite but only once/when $this->StatusState is not yet set
        }

        return $qJobDescrID;
    }

    public function onScheduledExecution()
    {
        return $this->postLocalVideo();
    }
}
