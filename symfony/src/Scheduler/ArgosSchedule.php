<?php

namespace App\Scheduler;

use App\Message\CheckAlertsMessage;
use App\Message\CheckDbIntegrityMessage;
use App\Message\CleanMacCrapMessage;
use App\Message\DbMaintenanceMessage;
use App\Message\PollDockerMessage;
use App\Message\PollMacMetricsMessage;
use App\Message\PollMountsMessage;
use App\Message\PollSynologyMessage;
use App\Message\PollUnifiMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
class ArgosSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())
            ->add(RecurringMessage::every('10 seconds',  new PollDockerMessage()))
            ->add(RecurringMessage::every('10 seconds',  new PollMacMetricsMessage()))
            ->add(RecurringMessage::every('30 seconds',  new PollSynologyMessage()))
            ->add(RecurringMessage::every('60 seconds',  new PollUnifiMessage()))
            ->add(RecurringMessage::every('60 seconds',  new PollMountsMessage()))
            ->add(RecurringMessage::every('30 seconds',  new CheckAlertsMessage()))
            ->add(RecurringMessage::cron('0 3 * * *',    new CheckDbIntegrityMessage()))
            ->add(RecurringMessage::cron('30 3 * * *',   new CleanMacCrapMessage()))
            ->add(RecurringMessage::every('1 hour',      new DbMaintenanceMessage()));
    }
}
