<?php

namespace Codeages\Biz\Framework\Scheduler;

use Codeages\Biz\Framework\Scheduler\Service\Impl\SchedulerServiceImpl;

class Scheduler
{
    public function __construct($biz)
    {
        $this->biz = $biz;
    }

    public function create($jobDetail)
    {
        return $this->getSchedulerService()->create($jobDetail);
    }

    public function run()
    {
        $this->getSchedulerService()->run();
    }

    /**
     * @return SchedulerServiceImpl
     */
    protected function getSchedulerService()
    {
        return $this->biz->service('Scheduler:SchedulerService');
    }
}